<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\IpAddress;
use App\Services\IpAddressListing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Ad kutusunun önerileri (bkz. name-complete.js): kullanıcının görebildiği
 * kayıtların farklı adları. Sayfaya gömülmez; kapalı bölümün ve aramada
 * elenen kaydın adı HTML'e çıkmasın, ad kutusu açılınca bir kez alınır.
 */
class AddressNameController extends Controller implements HasMiddleware
{
    /** @return array<int, Middleware> */
    public static function middleware(): array
    {
        return [new Middleware('can:viewAny,'.IpAddress::class)];
    }

    public function __invoke(Request $request, IpAddressListing $listing): JsonResponse
    {
        return response()->json($listing->nameSuggestions($request->user()));
    }
}
