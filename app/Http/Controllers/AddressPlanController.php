<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\IpAddress;
use App\Services\AddressPlanListing;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * IPv4 blok planı: adres listesinin ağaç görünümü (bkz. Ipv4BlockPlan).
 * Yalnız okur; ekleme ve düzenleme liste ekranında kalır.
 */
class AddressPlanController extends Controller implements HasMiddleware
{
    /** @return array<int, Middleware> */
    public static function middleware(): array
    {
        return [new Middleware('can:viewAny,'.IpAddress::class)];
    }

    public function __invoke(Request $request, AddressPlanListing $listing): View
    {
        return view('ip-addresses.plan', $listing->forRequest($request));
    }
}
