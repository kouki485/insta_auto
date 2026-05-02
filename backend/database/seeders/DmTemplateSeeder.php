<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Account;
use App\Models\DmTemplate;
use Illuminate\Database\Seeder;

/**
 * 各テナントが運用開始時にゼロから書く必要をなくすため、
 * 7 言語の汎用 DM テンプレを投入する.
 *
 * プレースホルダー:
 *   {username}    → DM 送信先 IG ユーザー名
 *   {store_name}  → アカウントの store_name (テナント別に動的展開)
 *
 * テナント固有の文言(地域名・業種など)は、ダッシュボードから
 * /templates 画面でテンプレを上書きして反映する.
 */
class DmTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $account = Account::query()->first();
        if ($account === null) {
            $this->command?->warn('AccountSeeder が先に必要です。スキップしました。');

            return;
        }

        foreach ($this->templates() as $language => $template) {
            DmTemplate::query()->updateOrCreate(
                ['account_id' => $account->id, 'language' => $language],
                ['template' => $template, 'active' => true],
            );
        }
    }

    /**
     * @return array<string, string>
     */
    private function templates(): array
    {
        return [
            'en' => <<<'EOT'
Hi {username}! Thanks for visiting our city ✨
We're {store_name}. We'd love to host you while you're around.
Show this DM at our entrance for a small welcome gift 🎁
EOT,
            'zh-cn' => <<<'EOT'
你好 {username}!感谢您来到这座城市 ✨
我们是「{store_name}」,期待您来店光临。
出示此私信即可获赠一份小礼物 🎁
EOT,
            'zh-tw' => <<<'EOT'
您好 {username}!感謝您造訪這座城市 ✨
我們是「{store_name}」,期待您的蒞臨。
出示此私訊即可獲贈一份小禮物 🎁
EOT,
            'ko' => <<<'EOT'
안녕하세요 {username}님! 저희 도시에 오신 것을 환영합니다 ✨
저희는 {store_name}입니다. 방문해 주시면 정말 기쁘겠습니다.
입구에서 이 DM을 보여주시면 작은 선물을 드립니다 🎁
EOT,
            'th' => <<<'EOT'
สวัสดีค่ะ {username}! ขอบคุณที่มาเยือนเมืองของเรา ✨
เราคือ {store_name} ยินดีต้อนรับคุณค่ะ
แสดง DM นี้ที่หน้าร้านเพื่อรับของขวัญต้อนรับเล็ก ๆ 🎁
EOT,
            'fr' => <<<'EOT'
Bonjour {username} ! Merci de visiter notre ville ✨
Nous sommes {store_name}. Nous serions ravis de vous accueillir.
Présentez ce DM à l'entrée pour recevoir un petit cadeau 🎁
EOT,
            'es' => <<<'EOT'
¡Hola {username}! Gracias por visitar nuestra ciudad ✨
Somos {store_name}. Nos encantaría recibirte.
Muestra este DM en la entrada y recibe un pequeño obsequio 🎁
EOT,
        ];
    }
}
