<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\IpAddress;
use App\Services\Export\AddressExport;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Adres listesini Excel dosyası olarak verir (bkz. AddressExport).
 *
 * Adres POST'tur: GET olsaydı bağlantı ön yüklemesi ya da tarayıcı geçmişi
 * istenmeden çıktı alır, işlem geçmişine düşerdi. Dosya tarayıcıya iner,
 * sunucuda bir yere yazılmaz.
 */
class AddressExportController extends Controller implements HasMiddleware
{
    /** @return array<int, Middleware> */
    public static function middleware(): array
    {
        return [new Middleware('can:exportAny,'.IpAddress::class)];
    }

    public function __invoke(Request $request, AddressExport $export): Response
    {
        $file = $export->build(
            $request->user(),
            $request->string('grup')->toString() ?: null,
            $request->string('q')->trim()->limit(100, '')->toString(),
        );

        return response($file['content'])
            ->header('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->header('Content-Disposition', 'attachment; filename="'.$file['name'].'"')
            ->header('Cache-Control', 'no-store, private');
    }
}
