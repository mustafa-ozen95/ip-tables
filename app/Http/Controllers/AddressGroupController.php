<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\GroupKind;
use App\Http\Requests\AddressGroup\DestroyAddressGroupRequest;
use App\Http\Requests\AddressGroup\StoreAddressGroupRequest;
use App\Http\Requests\AddressGroup\UpdateAddressGroupRequest;
use App\Http\Requests\ReorderRequest;
use App\Models\AddressGroup;
use App\Services\AddressGroupListing;
use App\Services\AddressGroupWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * Grup yönetimi — yalnızca yönetici (bkz. AddressGroupPolicy).
 */
class AddressGroupController extends Controller implements HasMiddleware
{
    public function __construct(
        private AddressGroupListing $listing,
        private AddressGroupWriter $writer,
    ) {}

    /** @return array<int, Middleware> */
    public static function middleware(): array
    {
        return [
            new Middleware('can:create,App\Models\AddressGroup', only: ['index', 'create', 'store', 'reorder']),
            new Middleware('can:update,group', only: ['edit', 'update']),
        ];
    }

    public function index(Request $request): View
    {
        return view('groups.index', $this->listing->forRequest($request));
    }

    public function create(): View
    {
        return view('groups.create', ['kinds' => GroupKind::options()]);
    }

    public function store(StoreAddressGroupRequest $request): RedirectResponse
    {
        $group = $this->writer->create($request->payload());

        return redirect()->route('groups.index')->with('status', "{$group->name} eklendi.");
    }

    public function edit(AddressGroup $group): View
    {
        return view('groups.edit', ['group' => $group, 'kinds' => GroupKind::options()]);
    }

    public function update(UpdateAddressGroupRequest $request, AddressGroup $group): RedirectResponse
    {
        $this->writer->update($group, $request->payload());

        return redirect()->route('groups.index')->with('status', "{$group->name} güncellendi.");
    }

    /** Grupları sürükleyerek sıralar (bkz. sorting.js). */
    public function reorder(ReorderRequest $request): RedirectResponse
    {
        $this->writer->reorder($request->uuids());

        return back()->with('status', 'Grup sırası güncellendi.');
    }

    public function destroy(DestroyAddressGroupRequest $request, AddressGroup $group): RedirectResponse
    {
        $this->writer->delete($group, $request->string('reason')->value());

        return redirect()->route('groups.index')->with('status', "{$group->name} silindi.");
    }
}
