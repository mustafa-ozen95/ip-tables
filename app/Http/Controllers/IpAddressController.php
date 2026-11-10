<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\IpAddress\DestroyIpAddressRequest;
use App\Http\Requests\IpAddress\StoreIpAddressRequest;
use App\Http\Requests\IpAddress\UpdateIpAddressRequest;
use App\Models\IpAddress;
use App\Services\IpAddressListing;
use App\Services\IpAddressWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * Ana ekran: gruplar | adres listesi | ayrıntı. Ekleme ve düzenleme aynı
 * ekranda pencerede açılır (?pencere=ekle|duzenle).
 */
class IpAddressController extends Controller implements HasMiddleware
{
    public function __construct(private IpAddressWriter $writer) {}

    public static function middleware(): array
    {
        return [
            new Middleware('can:viewAny,'.IpAddress::class, only: ['index']),
            new Middleware('can:create,'.IpAddress::class, only: ['store']),
            new Middleware('can:update,ipAddress', only: ['update']),
            new Middleware('can:delete,ipAddress', only: ['destroy']),
        ];
    }

    public function index(Request $request, IpAddressListing $listing): View
    {
        return view('ip-addresses.index', $listing->forRequest($request));
    }

    public function store(StoreIpAddressRequest $request): RedirectResponse
    {
        $ipAddress = $this->writer->create($request->payload());

        return $this->show($ipAddress, "{$ipAddress->notation} eklendi.");
    }

    public function update(UpdateIpAddressRequest $request, IpAddress $ipAddress): RedirectResponse
    {
        $this->writer->update($ipAddress, $request->payload());

        return $this->show($ipAddress, "{$ipAddress->notation} güncellendi.");
    }

    public function destroy(DestroyIpAddressRequest $request, IpAddress $ipAddress): RedirectResponse
    {
        $this->writer->delete($ipAddress, $request->string('reason')->value());

        return redirect()
            ->route('ip-addresses.index', ['grup' => $ipAddress->group->uuid])
            ->with('status', "{$ipAddress->notation} silindi.");
    }

    private function show(IpAddress $ipAddress, string $message): RedirectResponse
    {
        return redirect()
            ->route('ip-addresses.index', ['grup' => $ipAddress->group->uuid, 'kayit' => $ipAddress->uuid])
            ->with('status', $message);
    }
}
