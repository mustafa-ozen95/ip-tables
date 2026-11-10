<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ReorderRequest;
use App\Http\Requests\SubGroup\SubGroupRequest;
use App\Models\AddressGroup;
use App\Models\SubGroup;
use App\Services\SubGroupWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Alt grup yönetimi — Gruplar sayfasında, grubun bölümünün içinde.
 *
 * Ayrı ekranı yok: alt gruplar grubun bilgileridir ve grubun yanında
 * yönetilir. Ekleme ve düzenleme pencerede (bkz.
 * groups/partials/sub-window), sıra sürükleyerek.
 *
 * Alt grup adresteki grubun İÇİNDEN çözülür (rotada scopeBindings): başka
 * grubun alt grubu bu grubun adresiyle 404'tür, yetki yanlış gruba sorulmaz.
 *
 * Kaldırmak adres silmez; yine de onay sorulur — otuz adresin başlığını
 * kazara kaldırmak geri alınması zahmetli bir iştir.
 */
class SubGroupController extends Controller implements HasMiddleware
{
    public function __construct(private SubGroupWriter $writer) {}

    /** @return array<int, Middleware> */
    public static function middleware(): array
    {
        return [new Middleware('can:update,group')];
    }

    public function store(SubGroupRequest $request, AddressGroup $group): RedirectResponse
    {
        $subGroup = $this->writer->create($group, $request->payload());

        return redirect()->route('groups.index', ['acik' => $group->uuid])->with('status', "{$subGroup->name} eklendi.");
    }

    public function update(SubGroupRequest $request, AddressGroup $group, SubGroup $subGroup): RedirectResponse
    {
        $this->writer->update($subGroup, $request->payload());

        return redirect()->route('groups.index', ['acik' => $group->uuid])->with('status', "{$subGroup->name} güncellendi.");
    }

    /** Alt grupları sürükleyerek sıralar (bkz. sorting.js). */
    public function reorder(ReorderRequest $request, AddressGroup $group): RedirectResponse
    {
        $this->writer->reorder($group, $request->uuids());

        return back()->with('status', "{$group->name} alt grup sırası güncellendi.");
    }

    public function destroy(Request $request, AddressGroup $group, SubGroup $subGroup): RedirectResponse
    {
        $this->writer->delete($subGroup, $request->string('reason')->value() ?: null);

        return redirect()->route('groups.index', ['acik' => $group->uuid])->with('status', "{$subGroup->name} kaldırıldı; adresleri grupta duruyor.");
    }
}
