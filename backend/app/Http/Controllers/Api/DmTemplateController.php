<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DmTemplate;
use App\Support\CurrentAccount;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DmTemplateController extends Controller
{
    public function index(): JsonResponse
    {
        $account = CurrentAccount::resolve();

        return response()->json([
            'data' => DmTemplate::query()
                ->where('account_id', $account->id)
                ->orderBy('language')
                ->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'language' => ['required', 'string', 'max:10'],
            'template' => ['required', 'string'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $account = CurrentAccount::resolve();

        $template = DmTemplate::query()->updateOrCreate(
            ['account_id' => $account->id, 'language' => $payload['language']],
            [
                'template' => $payload['template'],
                'active' => $payload['active'] ?? true,
            ],
        );

        return response()->json(['data' => $template], 201);
    }

    public function update(Request $request, DmTemplate $template): JsonResponse
    {
        $account = CurrentAccount::resolve();
        if ($template->account_id !== $account->id) {
            throw (new ModelNotFoundException())->setModel(DmTemplate::class);
        }

        $payload = $request->validate([
            'template' => ['sometimes', 'string'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $template->fill($payload)->save();

        return response()->json(['data' => $template]);
    }
}
