<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\IpAddress\SplitIpAddressRequest;
use App\Http\Requests\IpAddress\UnsplitIpAddressRequest;
use App\Models\IpAddress;
use App\Services\IpAddressWriter;
use App\Services\Ipv4BlockRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * /24 bloğun bölmesi: böl ve bölmeyi kaldır. İkisi de listeye, bloğu
 * seçili olarak döner; bölmeden sonra parçaları da açık gelir.
 */
class AddressSplitController extends Controller implements HasMiddleware
{
    public function __construct(private IpAddressWriter $writer) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:update,ipAddress', only: ['store']),
            new Middleware('can:delete,ipAddress', only: ['destroy']),
        ];
    }

    public function store(SplitIpAddressRequest $request, IpAddress $ipAddress): RedirectResponse
    {
        $parts = $request->parts();
        $this->writer->split($ipAddress, $parts, $request->layout());

        return $this->show($ipAddress, "{$ipAddress->notation} ".count($parts).' parçaya bölündü.', open: true);
    }

    public function destroy(UnsplitIpAddressRequest $request, IpAddress $ipAddress, Ipv4BlockRules $rules): RedirectResponse
    {
        $this->writer->unsplit($ipAddress, $rules->parts($ipAddress), $request->string('reason')->value());

        return $this->show($ipAddress, "{$ipAddress->notation} bölmesi kaldırıldı.");
    }

    private function show(IpAddress $ipAddress, string $message, bool $open = false): RedirectResponse
    {
        return redirect()
            ->route('ip-addresses.index', [
                'grup' => $ipAddress->group->uuid,
                'kayit' => $ipAddress->uuid,
                'blok' => $open ? $ipAddress->uuid : null,
            ])
            ->with('status', $message);
    }
}
