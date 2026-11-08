<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessException;
use App\Http\Resources\AlerteResource;
use App\Models\Alerte;
use App\Support\AuthUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AlerteController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user       = AuthUser::fromRequest($request);
        $vehiculeId = $request->integer('vehicule_id') ?: null;

        if ($user->estChauffeur()) {
            if ($user->vehiculeId === null || ($vehiculeId !== null && $vehiculeId !== $user->vehiculeId)) {
                throw new BusinessException('Vous ne pouvez consulter que les alertes de votre véhicule.', 403, 'ACCES_REFUSE');
            }
            $vehiculeId = $user->vehiculeId;
        }

        $query = Alerte::query()->orderByDesc('id')
            ->when(!$request->boolean('inclure_resolues'), fn ($q) => $q->whereNull('resolue_at'))
            ->when($vehiculeId, fn ($q) => $q->where('vehicule_id', $vehiculeId))
            ->when($request->filled('type'), fn ($q) => $q->where('type', strtoupper((string) $request->input('type'))));

        return AlerteResource::collection($query->paginate(min(max($request->integer('per_page', 15), 1), 100)));
    }
}
