<?php
/**
 * Обръщение към Anthropic API за черновa на обобщен анализ.
 * Ако AI_ENABLED е false, системата работи напълно без него –
 * председателят просто пише доклада ръчно.
 */
final class Ai
{
    public static function available(): bool
    {
        return AI_ENABLED && AI_API_KEY !== '' && function_exists('curl_init');
    }

    /**
     * @param string $prompt Готовият текст с числата и темите на МО.
     * @return string Текстът, върнат от модела.
     * @throws RuntimeException
     */
    public static function summarize(string $prompt): string
    {
        if (!self::available()) {
            throw new RuntimeException('AI обобщението е изключено. Включете AI_ENABLED и въведете ключ в config.php.');
        }

        $payload = [
            'model'      => AI_MODEL,
            'max_tokens' => 2000,
            'system'     => 'Ти си помощник на председател на методическо обединение в българско '
                          . 'училище. Пишеш на официален български език, кратко и по същество, '
                          . 'без празни фрази. Използваш САМО подадените данни – не измисляш числа, '
                          . 'имена или факти. Ако липсват данни, го казваш изрично.',
            'messages'   => [['role' => 'user', 'content' => $prompt]],
        ];

        $ch = curl_init(AI_ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . AI_API_KEY,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($res === false) {
            throw new RuntimeException('Няма връзка с AI услугата: ' . $err);
        }
        $data = json_decode($res, true);
        if ($code !== 200) {
            $m = $data['error']['message'] ?? ('HTTP ' . $code);
            throw new RuntimeException('AI услугата върна грешка: ' . $m);
        }

        $text = '';
        foreach (($data['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') $text .= $block['text'];
        }
        return trim($text) !== '' ? trim($text) : 'Моделът не върна текст.';
    }

    /** Съставя промпта от обобщените данни на МО. */
    public static function buildPrompt(array $ctx): string
    {
        $p  = "Изготви чернова на обобщен анализ на методическо обединение.\n\n";
        $p .= "МО: {$ctx['department']}\nУчебна година: {$ctx['year']}\nПериод: {$ctx['term']}\n\n";
        $p .= "ОБОБЩЕНИ ЧИСЛА\n";
        $p .= "- Попълнени записа: {$ctx['records']}\n";
        $p .= "- Общо поставени оценки: {$ctx['grades']}\n";
        $p .= "- Среден успех на МО: {$ctx['avg']}\n";
        $p .= "- Слаби оценки (2): {$ctx['weak']} ({$ctx['weak_pct']})\n";
        $p .= "- Отлични оценки (6): {$ctx['top']} ({$ctx['top_pct']})\n\n";

        $p .= "ПО ПРЕДМЕТИ\n";
        foreach ($ctx['by_subject'] as $r) {
            $p .= "- {$r['subject_name']}: среден успех {$r['avg']}, слаби {$r['weak_pct']}, записи {$r['records']}\n";
        }

        $p .= "\nПРОБЛЕМНИ ТЕМИ, ПРИЧИНИ И МЕРКИ (по думите на учителите)\n";
        foreach ($ctx['topics'] as $t) {
            $p .= "- [{$t['subject_name']} / {$t['class_name']}] тема: {$t['weakest_topic']}; "
                . "причина: {$t['reason']}; мярка: {$t['measure']}\n";
        }

        $p .= "\nКОМПЕТЕНТНОСТИ (по ДОС/учебна програма) – дял на отчетените като усвоени\n";
        foreach ($ctx['competencies'] as $c) {
            $p .= "- {$c['title']}: усвоена {$c['ok']}, частично {$c['part']}, неусвоена {$c['no']}\n";
        }

        $p .= "\nЗАДАЧА\n";
        $p .= "Върни текст с точно тези четири раздела и нищо друго:\n";
        $p .= "1. ТРИ СИЛНИ СТРАНИ – по едно изречение, всяко подкрепено с число от данните.\n";
        $p .= "2. ТРИ ОБЛАСТИ ЗА ПОДОБРЕНИЕ – изведени от проблемните теми и неусвоените компетентности, не от общи усещания.\n";
        $p .= "3. ТРИ МЕРКИ ЗА СЛЕДВАЩИЯ ПЕРИОД – всяка с предложен отговорник и срок.\n";
        $p .= "4. ДРУГИ – до три изречения; тук отбележи и евентуално разминаване между ДОС, учебната програма и реалните резултати, ако данните го подсказват.\n";
        return $p;
    }
}
