<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Account;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * MVP では単一アカウント運用だが、SaaS 化を見越して全 Controller / Service が
 * このヘルパー経由で「現在操作対象のアカウント」を取得するように統一する。
 * Phase 1 では先頭 1 件を返す。Phase 5+ では認証ユーザー → アカウントの紐付けに置換予定。
 */
final class CurrentAccount
{
    public static function resolve(?int $accountId = null): Account
    {
        $query = Account::query();
        if ($accountId !== null) {
            return $query->where('id', $accountId)->firstOrFail();
        }

        $account = $query->orderBy('id')->first();
        if ($account === null) {
            throw (new ModelNotFoundException())->setModel(Account::class);
        }

        return $account;
    }
}
