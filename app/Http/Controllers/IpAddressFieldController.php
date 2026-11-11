<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\IpAddress\UpdateIpAddressFieldRequest;
use App\Models\IpAddress;
use App\Services\IpAddressWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Listede çift tıklayarak ad / açıklama düzenleme (bkz. inline-edit.js).
 *
 * Kullanıcı bulunduğu listeye geri döner (grup, arama, açık bloklar yerinde
 * kalır); düzenleme penceresi gibi kaydı seçip listeyi değiştirmez.
 */
class IpAddressFieldController extends Controller implements HasMiddleware
{
    public function __construct(private IpAddressWriter $writer) {}

    public static function middleware(): array
    {
        return [new Middleware('can:update,ipAddress')];
    }

    public function update(UpdateIpAddressFieldRequest $request, IpAddress $ipAddress): RedirectResponse
    {
        $this->writer->updateField($ipAddress, $request->field(), $request->value());

        $label = $request->field() === 'description' ? 'açıklaması' : 'adı';

        return back()->with('status', "{$ipAddress->notation} {$label} güncellendi.");
    }
}
