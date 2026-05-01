<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Account;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * MVP では単一アカウント運用だが、SaaS 化を見越して全 Controller / Service が
 * このヘルパー経由で「現在操作対象のアカウント」を取得するように統一する.
 *
 * 現状はクエリパラメータ ?account_id=N を受け取れば任意アカウントを参照できる.
 * Sanctum 認証は前段でかかっているがフェールセーフは無いため、
 * TODO(Phase 6): authenticated user → account を 1:1 でバインドし、
 * 任意アカウントへのアクセスを禁止する.
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
