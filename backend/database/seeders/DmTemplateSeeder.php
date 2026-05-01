<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Account;
use App\Models\DmTemplate;
use Illuminate\Database\Seeder;

class DmTemplateSeeder extends Seeder
{
    /**
     * 設計書 付録B のテンプレートを 7 言語投入する.
     */
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
Hi {username}! Welcome to Asakusa 🇯🇵
We're Unara, a small unagi (eel) restaurant near Sensoji.
Show this DM at our entrance for a free appetizer 🍶
Hope to see you soon!
EOT,
            'zh-cn' => <<<'EOT'
你好 {username}!欢迎来到浅草 🇯🇵
我们是浅草寺附近的鳗鱼料理店「うなら」
出示此私信即可获赠一份小菜 🍶
期待您的光临!
EOT,
            'zh-tw' => <<<'EOT'
您好 {username}!歡迎來到淺草 🇯🇵
我們是淺草寺附近的鰻魚料理店「うなら」
出示此私訊即可獲贈一份小菜 🍶
期待您的光臨!
EOT,
            'ko' => <<<'EOT'
안녕하세요 {username}님! 아사쿠사에 오신 것을 환영합니다 🇯🇵
센소지 근처의 장어 요리 전문점 우나라입니다.
입구에서 이 DM을 보여주시면 사이드 메뉴를 무료로 드립니다 🍶
방문을 기다리고 있겠습니다!
EOT,
            'th' => <<<'EOT'
สวัสดีค่ะ {username}! ยินดีต้อนรับสู่อาซากุสะ 🇯🇵
เราคือร้านปลาไหลย่าง Unara ใกล้วัดเซ็นโซจิ
แสดง DM นี้ที่หน้าร้าน รับของแถมฟรี 1 จาน 🍶
รอพบคุณค่ะ!
EOT,
            'fr' => <<<'EOT'
Bonjour {username} ! Bienvenue à Asakusa 🇯🇵
Nous sommes Unara, un restaurant traditionnel d'anguille (unagi) près de Sensoji.
Présentez ce DM à l'entrée pour recevoir une entrée offerte 🍶
À très bientôt !
EOT,
            'es' => <<<'EOT'
¡Hola {username}! Bienvenido a Asakusa 🇯🇵
Somos Unara, un restaurante tradicional de anguila (unagi) cerca de Sensoji.
Muestra este DM en la entrada y recibe un aperitivo gratis 🍶
¡Esperamos verte pronto!
EOT,
        ];
    }
}
