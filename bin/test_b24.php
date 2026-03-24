<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Services\Bitrix24Client;

$webhookUrl = $_ENV['B24_WEBHOOK_URL'] ?? '';
$dealId = (int)($_ENV['B24_DEAL_ID'] ?? 0);

if ($dealId <= 0) {
    exit("B24_DEAL_ID is invalid\n");
}

$client = new Bitrix24Client($webhookUrl);

try {
    echo "=== DEAL ===\n";
    $deal = $client->getDeal($dealId);
    print_r($deal);

    echo "\n=== DEAL CONTACTS ===\n";
    $dealContacts = $client->getDealContacts($dealId);
    print_r($dealContacts);

    $contactId = 0;

    if (!empty($dealContacts[0]['CONTACT_ID'])) {
        $contactId = (int)$dealContacts[0]['CONTACT_ID'];
    } elseif (!empty($deal['CONTACT_ID'])) {
        $contactId = (int)$deal['CONTACT_ID'];
    }

    if ($contactId <= 0) {
        throw new RuntimeException('Contact not found for deal');
    }

    echo "\n=== CONTACT ===\n";
    $contact = $client->getContact($contactId);
    print_r($contact);

    $phone = $client->extractPrimaryValue($contact['PHONE'] ?? []);
    $email = $client->extractPrimaryValue($contact['EMAIL'] ?? []);

    echo "\n=== SUMMARY ===\n";
    echo 'Deal: ' . ($deal['TITLE'] ?? '') . PHP_EOL;
    echo 'Stage: ' . ($deal['STAGE_ID'] ?? '') . PHP_EOL;
    echo 'Contact ID: ' . $contactId . PHP_EOL;
    echo 'Contact: ' . trim(($contact['NAME'] ?? '') . ' ' . ($contact['LAST_NAME'] ?? '')) . PHP_EOL;
    echo 'Phone: ' . ($phone ?: 'not found') . PHP_EOL;
    echo 'Email: ' . ($email ?: 'not found') . PHP_EOL;
} catch (Throwable $e) {
    echo "\n[ERROR] " . $e->getMessage() . PHP_EOL;
}
