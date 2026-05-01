<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Prospect;
use App\Support\CurrentAccount;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProspectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $params = $request->validate([
            'account_id' => ['sometimes', 'integer'],
            'status' => ['sometimes', 'string', 'in:new,queued,dm_sent,replied,skipped,blacklisted'],
            'min_score' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $account = CurrentAccount::resolve($params['account_id'] ?? null);

        $query = Prospect::query()
            ->where('account_id', $account->id)
            ->orderByDesc('tourist_score')
            ->orderBy('found_at');

        if (isset($params['status'])) {
            $query->where('status', $params['status']);
        }
        if (isset($params['min_score'])) {
            $query->where('tourist_score', '>=', $params['min_score']);
        }

        return response()->json($query->paginate($params['per_page'] ?? 50));
    }

    public function update(Request $request, Prospect $prospect): JsonResponse
    {
        $this->ensureAccessible($prospect);

        $payload = $request->validate([
            'status' => ['required', 'string', 'in:queued,skipped,blacklisted'],
        ]);

        $prospect->update($payload);

        return response()->json(['data' => $prospect]);
    }

    public function destroy(Prospect $prospect): JsonResponse
    {
        $this->ensureAccessible($prospect);

        $prospect->update(['status' => Prospect::STATUS_BLACKLISTED]);

        return response()->json(['data' => $prospect]);
    }

    /**
     * SaaS 化を見越し、ルートパラメータ {prospect} が現在のアカウントに紐づくか必ず確認する.
     */
    private function ensureAccessible(Prospect $prospect): void
    {
        $account = CurrentAccount::resolve();
        if ($prospect->account_id !== $account->id) {
            throw (new ModelNotFoundException())->setModel(Prospect::class);
        }
    }
}
