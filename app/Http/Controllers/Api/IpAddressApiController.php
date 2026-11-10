<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\IpAddressIndexRequest;
use App\Http\Resources\IpAddressResource;
use App\Models\IpAddress;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Salt okunur adres API'si. Yanıt isteği yapanın GÖREBİLDİĞİ gruplarla
 * sınırlıdır; görülmeyen adres 404 döner (var olduğu bile söylenmez).
 */
class IpAddressApiController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware('can:viewAny,'.IpAddress::class)];
    }

    public function index(IpAddressIndexRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        $addresses = IpAddress::query()
            ->with(['group', 'subGroup'])
            ->visibleTo($request->user())
            ->when($filters['grup'] ?? null, fn ($q, string $uuid) => $q->whereRelation('group', 'uuid', $uuid))
            ->when($filters['tur'] ?? null, fn ($q, string $kind) => $q->whereRelation('group', 'kind', $kind))
            ->search($filters['q'] ?? null)
            ->ordered()
            ->paginate(config('api.per_page'));

        return IpAddressResource::collection($addresses);
    }

    public function show(Request $request, string $ipAddress): IpAddressResource
    {
        return new IpAddressResource(IpAddress::query()
            ->with(['group', 'subGroup'])
            ->visibleTo($request->user())
            ->where('uuid', $ipAddress)
            ->firstOrFail());
    }
}
