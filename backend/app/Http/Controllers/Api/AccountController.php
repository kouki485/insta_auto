<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Account::query()
                ->orderBy('id')
                ->get()
                ->map(fn (Account $account) => $this->toResource($account)),
        ]);
    }

    public function show(Account $account): JsonResponse
    {
        return response()->json(['data' => $this->toResource($account)]);
    }

    public function update(Request $request, Account $account): JsonResponse
    {
        $payload = $request->validate([
            'store_name' => ['sometimes', 'string', 'max:100'],
            'daily_dm_limit' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'daily_follow_limit' => ['sometimes', 'integer', 'min:0', 'max:200'],
            'daily_like_limit' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'timezone' => ['sometimes', 'string', 'max:50'],
        ]);

        $account->fill($payload)->save();

        return response()->json(['data' => $this->toResource($account)]);
    }

    public function pause(Account $account): JsonResponse
    {
        $account->update(['status' => Account::STATUS_PAUSED]);

        return response()->json(['data' => $this->toResource($account)]);
    }

    public function resume(Account $account): JsonResponse
    {
        $account->update([
            'status' => Account::STATUS_ACTIVE,
            'daily_dm_limit' => 5, // 設計書 §4.4 再開後は 5/日 から再ウォームアップ
        ]);

        return response()->json(['data' => $this->toResource($account)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function toResource(Account $account): array
    {
        return [
            'id' => $account->id,
            'store_name' => $account->store_name,
            'ig_username' => $account->ig_username,
            'status' => $account->status,
            'daily_dm_limit' => $account->daily_dm_limit,
            'daily_follow_limit' => $account->daily_follow_limit,
            'daily_like_limit' => $account->daily_like_limit,
            'timezone' => $account->timezone,
            'warmup_started_at' => $account->warmup_started_at?->toIso8601String(),
            'updated_at' => $account->updated_at?->toIso8601String(),
            // proxy_url / ig_password は応答に含めない (機密)
        ];
    }
}
