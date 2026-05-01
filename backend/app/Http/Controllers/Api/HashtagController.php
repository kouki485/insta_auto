<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HashtagWatchlist;
use App\Support\CurrentAccount;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HashtagController extends Controller
{
    public function index(): JsonResponse
    {
        $account = CurrentAccount::resolve();

        return response()->json([
            'data' => HashtagWatchlist::query()
                ->where('account_id', $account->id)
                ->orderByDesc('priority')
                ->orderBy('hashtag')
                ->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'hashtag' => ['required', 'string', 'max:100', 'regex:/^[^\s#]+$/u'],
            'language' => ['sometimes', 'nullable', 'string', 'max:10'],
            'priority' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $account = CurrentAccount::resolve();

        $hashtag = HashtagWatchlist::query()->updateOrCreate(
            ['account_id' => $account->id, 'hashtag' => $payload['hashtag']],
            [
                'language' => $payload['language'] ?? null,
                'priority' => $payload['priority'] ?? 5,
                'active' => $payload['active'] ?? true,
            ],
        );

        return response()->json(['data' => $hashtag], 201);
    }

    public function destroy(HashtagWatchlist $hashtag): JsonResponse
    {
        $account = CurrentAccount::resolve();
        if ($hashtag->account_id !== $account->id) {
            throw (new ModelNotFoundException())->setModel(HashtagWatchlist::class);
        }
        $hashtag->delete();

        return response()->json(['data' => ['ok' => true]]);
    }
}
