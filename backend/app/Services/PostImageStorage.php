<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Account;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * 投稿画像のディスク保存ヘルパ.
 * 設計書 §3.3.1: storage/app/public/images/{account_id}/{Y}/{m}/{uuid}.{ext}
 */
final class PostImageStorage
{
    public const DISK = 'public';

    public function store(Account $account, UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        if (! in_array($extension, ['jpg', 'jpeg', 'png'], true)) {
            $extension = $file->getMimeType() === 'image/png' ? 'png' : 'jpg';
        }

        $directory = sprintf('images/%d/%s', $account->id, now($account->timezone)->format('Y/m'));
        $filename = Str::uuid()->toString().'.'.$extension;

        $path = $file->storeAs($directory, $filename, ['disk' => self::DISK]);

        if ($path === false || $path === '') {
            throw new \RuntimeException('failed to store image');
        }

        return $path;
    }

    public function url(string $path): string
    {
        return Storage::disk(self::DISK)->url($path);
    }

    public function absolutePath(string $path): string
    {
        return Storage::disk(self::DISK)->path($path);
    }

    public function delete(string $path): void
    {
        Storage::disk(self::DISK)->delete($path);
    }
}
