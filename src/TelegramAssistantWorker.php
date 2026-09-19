<?php
declare(strict_types=1);

namespace VazinCMS;

use RuntimeException;
use Throwable;

/** Delivers the independent Telegram Business outbox under explicit gates. */
final class TelegramAssistantWorker
{
    /**
     * @return array{status:string,processed:int,sent:int,failed:int,cancelled:int,budget_exhausted:bool}
     */
    public static function process(int $limit = 20, ?callable $transportFactory = null, float $timeBudgetSeconds = 40.0, ?callable $clock = null): array
    {
        if (!DeliveryPolicy::telegramAssistantDeliveryEnabled()) {
            return ['status'=>'disabled','processed'=>0,'sent'=>0,'failed'=>0,'cancelled'=>0,'budget_exhausted'=>false];
        }

        $repository = new TelegramAssistantRepository();
        $business = new TelegramBusinessService($repository);
        $outbox = new TelegramAssistantOutbox(
            $repository,
            $business,
            static fn(array $context): bool => DeliveryPolicy::telegramAssistantDeliveryEnabled()
        );
        $outbox->releaseStale();
        $limit=max(1,min(100,$limit));$timeBudgetSeconds=max(.05,min(220.0,$timeBudgetSeconds));
        $clock??=static fn():float=>hrtime(true)/1_000_000_000;
        $deadline=$clock()+$timeBudgetSeconds;
        $result = ['status'=>'enabled','processed'=>0,'sent'=>0,'failed'=>0,'cancelled'=>0,'budget_exhausted'=>false];

        while($result['processed']<$limit){
            if($clock()>=$deadline){$result['budget_exhausted']=true;break;}
            $jobs=$outbox->claim(1);if($jobs===[]){if($outbox->hasDuePending())continue;break;}$job=$jobs[0];
            $result['processed']++;
            try {
                $delivered=$outbox->deliverClaimed((int)$job['id'],(string)$job['lock_token'],static function(array$freshJob)use($transportFactory):array{
                    $connection=TelegramSyncService::connectionById((int)$freshJob['connection_id']);
                    if((int)$connection['is_enabled']!==1)throw new RuntimeException('اتصال Telegram غیرفعال است.');
                    $transport=$transportFactory===null?null:$transportFactory($connection,$freshJob);
                    if($transport!==null&&!is_callable($transport))throw new RuntimeException('انتقال آزمایشی Telegram معتبر نیست.');
                    return self::deliver(new TelegramBotApi(TelegramSyncService::botToken($connection),$transport),$freshJob);
                });
                if(($delivered['status']??'')==='sent')$result['sent']++;else$result['cancelled']++;
            } catch (Throwable $error) {
                $retryAfter = $error instanceof TelegramBotApiException && $error->retryAfter() > 0
                    ? $error->retryAfter()
                    : null;
                $safeError = $error instanceof TelegramBotApiException
                    ? 'telegram_api_' . max(0, $error->telegramErrorCode())
                    : 'assistant_delivery_failed';
                $outbox->markFailed((int)$job['id'], (string)$job['lock_token'], $safeError, $retryAfter);
                $result['failed']++;
            }
        }

        return $result;
    }

    private static function deliver(TelegramBotApi $api, array $job): array
    {
        $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
        $base = [
            'business_connection_id'=>(string)$job['business_connection_id'],
            'chat_id'=>(string)$job['chat_id'],
        ];

        return match ((string)$job['action']) {
            'send_message' => $api->call('sendMessage', $base + self::messagePayload($payload, $job)),
            'edit_message' => $api->call('editMessageText', $base + self::editPayload($payload)),
            'chat_action' => $api->call('sendChatAction', $base + [
                'action'=>in_array((string)($payload['action'] ?? ''), ['typing','upload_photo','upload_document','record_voice'], true)
                    ? (string)$payload['action'] : 'typing',
            ]),
            'callback_answer' => $api->call('answerCallbackQuery', self::callbackPayload($payload)),
            default => throw new RuntimeException('عملیات صف دستیار پشتیبانی نمی‌شود.'),
        };
    }

    private static function messagePayload(array $payload, array $job): array
    {
        $text = trim((string)($payload['text'] ?? ''));
        if ($text === '' || mb_strlen($text) > 4096) throw new RuntimeException('متن پاسخ دستیار معتبر نیست.');
        $parameters = ['text'=>$text, 'disable_web_page_preview'=>true];
        $replyTo = (int)($payload['reply_to_message_id'] ?? $job['reply_to_message_id'] ?? 0);
        if ($replyTo > 0) $parameters['reply_parameters'] = ['message_id'=>$replyTo, 'allow_sending_without_reply'=>true];
        if (is_array($payload['reply_markup'] ?? null)) $parameters['reply_markup'] = self::safeReplyMarkup($payload['reply_markup']);
        return $parameters;
    }

    private static function editPayload(array $payload): array
    {
        $messageId = filter_var($payload['message_id'] ?? null, FILTER_VALIDATE_INT);
        $text = trim((string)($payload['text'] ?? ''));
        if (!is_int($messageId) || $messageId < 1 || $text === '' || mb_strlen($text) > 4096) {
            throw new RuntimeException('ویرایش پاسخ دستیار معتبر نیست.');
        }
        return ['message_id'=>$messageId, 'text'=>$text, 'disable_web_page_preview'=>true];
    }

    private static function callbackPayload(array $payload): array
    {
        $id = trim((string)($payload['callback_query_id'] ?? ''));
        if ($id === '' || strlen($id) > 255) throw new RuntimeException('شناسهٔ callback معتبر نیست.');
        return [
            'callback_query_id'=>$id,
            'text'=>mb_substr(trim((string)($payload['text'] ?? '')), 0, 200),
            'show_alert'=>!empty($payload['show_alert']),
        ];
    }

    /** Only the small inline-keyboard subset generated by this module is accepted. */
    private static function safeReplyMarkup(array $markup): array
    {
        $rows = is_array($markup['inline_keyboard'] ?? null) ? $markup['inline_keyboard'] : [];
        $safe = [];
        foreach (array_slice($rows, 0, 8) as $row) {
            if (!is_array($row)) continue;
            $buttons = [];
            foreach (array_slice($row, 0, 8) as $button) {
                if (!is_array($button)) continue;
                $text = mb_substr(trim((string)($button['text'] ?? '')), 0, 64);
                $data = mb_substr(trim((string)($button['callback_data'] ?? '')), 0, 64);
                $webAppUrl = trim((string)($button['web_app']['url'] ?? ''));
                if ($text !== '' && $data !== '') {
                    $buttons[] = ['text'=>$text, 'callback_data'=>$data];
                } elseif ($text !== '' && self::safeMiniAppUrl($webAppUrl)) {
                    $buttons[] = ['text'=>$text, 'web_app'=>['url'=>$webAppUrl]];
                }
            }
            if ($buttons !== []) $safe[] = $buttons;
        }
        return ['inline_keyboard'=>$safe];
    }

    private static function safeMiniAppUrl(string $url): bool
    {
        $base = parse_url(rtrim(trim((string)getenv('APP_URL')),'/'));
        $candidate = parse_url($url);
        if (!is_array($base) || !is_array($candidate)
            || strtolower((string)($base['scheme']??'')) !== 'https'
            || strtolower((string)($candidate['scheme']??'')) !== 'https'
            || isset($candidate['user']) || isset($candidate['pass']) || isset($candidate['fragment'])) return false;
        $sameOrigin = strtolower((string)($base['host']??'')) === strtolower((string)($candidate['host']??''))
            && (int)($base['port']??443) === (int)($candidate['port']??443);
        return $sameOrigin && preg_match('#^/telegram/assistant/[a-f0-9]{32}$#',(string)($candidate['path']??''))===1;
    }
}
