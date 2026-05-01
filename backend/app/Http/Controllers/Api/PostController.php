<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PostSchedule;
use App\Services\PostImageStorage;
use App\Support\CurrentAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PostController extends Controller
{
    public function __construct(private readonly PostImageStorage $imageStorage) {}

    public function index(Request $request): JsonResponse
    {
        $params = $request->validate([
            'account_id' => ['sometimes', 'integer'],
            'status' => [
                'sometimes',
                'string',
                'in:scheduled,posting,posted,failed',
            ],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);
        $account = CurrentAccount::resolve($params['account_id'] ?? null);

        $query = PostSchedule::query()
            ->where('account_id', $account->id)
            ->orderByDesc('scheduled_at');

        if (isset($params['status'])) {
            $query->where('status', $params['status']);
        }

        return response()->json($query->paginate($params['per_page'] ?? 50));
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'type' => ['required', 'string', 'in:feed,story'],
            'image_path' => ['required', 'string', 'max:255'],
            'caption' => ['nullable', 'string', 'max:2200'],
            'scheduled_at' => ['required', 'date', 'after:now'],
        ]);

        $account = CurrentAccount::resolve();

        if (! Storage::disk(PostImageStorage::DISK)->exists($payload['image_path'])) {
            return response()->json([
                'message' => 'image_path に対応する画像が見つかりません。先に /api/posts/upload-image を呼んでください。',
            ], 422);
        }

        $post = PostSchedule::query()->create([
            'account_id' => $account->id,
            'type' => $payload['type'],
            'image_path' => $payload['image_path'],
            'caption' => $payload['caption'] ?? null,
            'scheduled_at' => $payload['scheduled_at'],
            'status' => PostSchedule::STATUS_SCHEDULED,
        ]);

        return response()->json(['data' => $post], 201);
    }

    public function destroy(int $post): JsonResponse
    {
        $account = CurrentAccount::resolve();
        // 他アカウントの post_id を指定された場合は 404 で存在しないかのように振る舞う.
        $target = PostSchedule::query()
            ->where('id', $post)
            ->where('account_id', $account->id)
            ->firstOrFail();

        if ($target->status !== PostSchedule::STATUS_SCHEDULED) {
            return response()->json([
                'message' => 'すでに送信処理が始まっているため削除できません。',
            ], 422);
        }
        $target->delete();

        return response()->json(['data' => ['ok' => true]]);
    }

    public function uploadImage(Request $request): JsonResponse
    {
        $request->validate([
            'image' => [
                'required',
                'file',
                'mimes:jpg,jpeg,png',
                'max:5120', // 5MB
                'dimensions:min_width=320,min_height=320,max_width=4096,max_height=4096',
            ],
        ]);

        $account = CurrentAccount::resolve();
        $path = $this->imageStorage->store($account, $request->file('image'));

        return response()->json([
            'data' => [
                'image_path' => $path,
                'url' => $this->imageStorage->url($path),
            ],
        ], 201);
    }
}
