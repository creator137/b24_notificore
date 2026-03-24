<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Services\Bitrix24Client;
use App\Services\NotificationClientFactory;
use App\Services\SendLogger;
use App\Services\TemplateRenderer;

$defaultDealId = (int)($_ENV['B24_DEAL_ID'] ?? 0);
$defaultChannel = (string)($_ENV['DEFAULT_CHANNEL'] ?? 'sms');
$logPath = (string)($_ENV['LOG_PATH'] ?? 'logs/send_log.jsonl');
$webhookUrl = (string)($_ENV['B24_WEBHOOK_URL'] ?? '');

$options = getopt('', ['deal::', 'channel::']);

$dealId = isset($options['deal']) ? (int)$options['deal'] : $defaultDealId;
$channel = isset($options['channel']) ? (string)$options['channel'] : $defaultChannel;
$channel = in_array($channel, ['sms', 'email'], true) ? $channel : 'sms';

if ($dealId <= 0) {
    exit("Deal ID is invalid\n");
}

$templates = require __DIR__ . '/../templates/messages.php';

$bitrix = new Bitrix24Client($webhookUrl);
$renderer = new TemplateRenderer($templates);
$notificationClient = NotificationClientFactory::make();
$logger = new SendLogger($logPath);

try {
    $deal = $bitrix->getDeal($dealId);
    $dealContacts = $bitrix->getDealContacts($dealId);

    $contactId = 0;

    if (!empty($dealContacts[0]['CONTACT_ID'])) {
        $contactId = (int)$dealContacts[0]['CONTACT_ID'];
    } elseif (!empty($deal['CONTACT_ID'])) {
        $contactId = (int)$deal['CONTACT_ID'];
    }

    if ($contactId <= 0) {
        throw new RuntimeException('Contact not found for deal');
    }

    $contact = $bitrix->getContact($contactId);

    $phone = $bitrix->extractPrimaryValue($contact['PHONE'] ?? []) ?? '';
    $email = $bitrix->extractPrimaryValue($contact['EMAIL'] ?? []) ?? '';
    $message = $renderer->renderDealStatusMessage($deal, $contact);

    if ($channel === 'sms') {
        $sendResult = $notificationClient->sendSms($phone, $message);
    } else {
        $subject = 'Уведомление по сделке ' . ($deal['TITLE'] ?? '');
        $sendResult = $notificationClient->sendEmail($email, $subject, $message);
    }

    $record = [
        'ts' => date('c'),
        'deal_id' => (int)$deal['ID'],
        'contact_id' => $contactId,
        'stage_id' => $deal['STAGE_ID'] ?? null,
        'channel' => $channel,
        'phone' => $phone,
        'email' => $email,
        'message' => $message,
        'send_result' => $sendResult,
    ];

    $logger->log($record);

    echo "=== SEND RESULT ===\n";
    print_r($record);
} catch (Throwable $e) {
    echo '[ERROR] ' . $e->getMessage() . PHP_EOL;
}
