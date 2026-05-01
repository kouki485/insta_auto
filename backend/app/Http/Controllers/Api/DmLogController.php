<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DmLog;
use App\Support\CurrentAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DmLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $params = $request->validate([
            'account_id' => ['sometimes', 'integer'],
            'status' => ['sometimes', 'string', 'in:queued,sent,failed,rate_limited,blocked'],
            'language' => ['sometimes', 'string', 'max:10'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        $account = CurrentAccount::resolve($params['account_id'] ?? null);

        $query = DmLog::query()
            ->where('account_id', $account->id)
            ->with('prospect:id,ig_username,detected_lang')
            ->orderByDesc('id');

        if (isset($params['status'])) {
            $query->where('status', $params['status']);
        }
        if (isset($params['language'])) {
            $query->where('language', $params['language']);
        }
        if (isset($params['from'])) {
            $query->where('created_at', '>=', $params['from']);
        }
        if (isset($params['to'])) {
            $query->where('created_at', '<=', $params['to']);
        }

        return response()->json($query->paginate($params['per_page'] ?? 50));
    }
}
