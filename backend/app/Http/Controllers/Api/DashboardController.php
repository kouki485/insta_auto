<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DashboardSummaryService;
use App\Support\CurrentAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardSummaryService $service) {}

    public function summary(Request $request): JsonResponse
    {
        $params = $request->validate([
            'account_id' => ['sometimes', 'integer'],
            'fresh' => ['sometimes', 'boolean'],
        ]);

        $account = CurrentAccount::resolve($params['account_id'] ?? null);

        if (! empty($params['fresh'])) {
            $this->service->forget($account->id);
        }

        $payload = $this->service->buildFor($account);
        if ($payload === null) {
            return response()->json(['data' => null]);
        }

        return response()->json(['data' => $payload]);
    }
}
