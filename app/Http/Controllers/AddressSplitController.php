<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\IpAddress\MergeIpAddressRequest;
use App\Http\Requests\IpAddress\SplitIpAddressRequest;
use App\Http\Requests\IpAddress\SubdivideIpAddressRequest;
use App\Http\Requests\IpAddress\UnsplitIpAddressRequest;
use App\Models\IpAddress;
use App\Services\IpAddressWriter;
use App\Services\Ipv4BlockRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * /24 bloğun bölmesi: böl, bölmeyi kaldır, parçayı birleştir ya da
 * parçala. Hepsi listeye, kaydı seçili ve bloğu açık olarak döner.
 */
class AddressSplitController extends Controller implements HasMiddleware
{
    public function __construct(private IpAddressWriter $writer, private Ipv4BlockRules $rules) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:update,ipAddress', only: ['store', 'merge', 'subdivide']),
            new Middleware('can:delete,ipAddress', only: ['destroy', 'merge']),
        ];
    }

    public function store(SplitIpAddressRequest $request, IpAddress $ipAddress): RedirectResponse
    {
        $parts = $request->parts();

        /* Önizleme: pencere aynı satırlarla, sonuç özetiyle yeniden açılır. */
        if ($request->preview()) {
            return redirect()
                ->route('ip-addresses.index', ['grup' => $ipAddress->group->uuid, 'kayit' => $ipAddress->uuid, 'pencere' => 'bol'])
                ->withInput($request->except('onizle'))
                ->with('splitPreview', count($parts).' parça: '.$request->layout());
        }

        $this->writer->split($ipAddress, $parts, $request->layout());

        return $this->show($ipAddress, $ipAddress, "{$ipAddress->notation} içinde ".count($parts).' parça açıldı.');
    }

    public function destroy(UnsplitIpAddressRequest $request, IpAddress $ipAddress): RedirectResponse
    {
        $this->writer->unsplit($ipAddress, $this->rules->parts($ipAddress), $request->string('reason')->value());

        return $this->show($ipAddress, null, "{$ipAddress->notation} bölmesi kaldırıldı.");
    }

    public function merge(MergeIpAddressRequest $request, IpAddress $ipAddress): RedirectResponse
    {
        $block = $this->rules->blockOf($ipAddress);
        $kept = $this->writer->merge($ipAddress, $request->buddy(), $request->merged());

        return $this->show($kept, $block, "Parçalar {$kept->notation} olarak birleştirildi.");
    }

    public function subdivide(SubdivideIpAddressRequest $request, IpAddress $ipAddress): RedirectResponse
    {
        $block = $this->rules->blockOf($ipAddress);
        $pieces = $request->pieces();
        $this->writer->subdivide($ipAddress, $pieces);

        return $this->show($ipAddress, $block, 'Parça '.count($pieces)." × /{$pieces[0]->prefix} olarak bölündü.");
    }

    /** $open: parçaları açık gelecek blok. */
    private function show(IpAddress $selected, ?IpAddress $open, string $message): RedirectResponse
    {
        return redirect()
            ->route('ip-addresses.index', [
                'grup' => $selected->group->uuid,
                'kayit' => $selected->uuid,
                'blok' => $open?->uuid,
            ])
            ->with('status', $message);
    }
}
