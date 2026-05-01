<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SafetyEvent;
use App\Support\CurrentAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SafetyEventController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $params = $request->validate([
            'account_id' => ['sometimes', 'integer'],
            'severity' => ['sometimes', 'string', 'in:info,warning,critical'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        $account = CurrentAccount::resolve($params['account_id'] ?? null);

        $query = SafetyEvent::query()
            ->where('account_id', $account->id)
            ->orderByDesc('occurred_at');
        if (isset($params['severity'])) {
            $query->where('severity', $params['severity']);
        }

        return response()->json($query->paginate($params['per_page'] ?? 50));
    }
}
