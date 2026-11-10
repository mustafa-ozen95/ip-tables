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
 * /24 bloğun bölmesi: böl ve bölmeyi kaldır. İkisi de blok planına,
 * bloğu açık olarak döner.
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

        return $this->plan($ipAddress, "{$ipAddress->notation} ".count($parts).' parçaya bölündü.');
    }

    public function destroy(UnsplitIpAddressRequest $request, IpAddress $ipAddress, Ipv4BlockRules $rules): RedirectResponse
    {
        $this->writer->unsplit($ipAddress, $rules->parts($ipAddress), $request->string('reason')->value());

        return $this->plan($ipAddress, "{$ipAddress->notation} bölmesi kaldırıldı.");
    }

    private function plan(IpAddress $ipAddress, string $message): RedirectResponse
    {
        return redirect()
            ->route('ip-addresses.plan', ['grup' => $ipAddress->group->uuid, 'blok' => $ipAddress->uuid])
            ->with('status', $message);
    }
}
