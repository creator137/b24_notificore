<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class Bitrix24Client
{
    public function __construct(
        private readonly string $webhookUrl
    ) {
        if ($this->webhookUrl === '') {
            throw new RuntimeException('B24_WEBHOOK_URL is empty');
        }
    }

    public function call(string $method, array $fields = []): array
    {
        $url = rtrim($this->webhookUrl, '/') . '/' . $method . '.json';

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);

        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('cURL error: ' . $error);
        }

        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400) {
            throw new RuntimeException('HTTP error: ' . $httpCode . ' / ' . $response);
        }

        $data = json_decode($response, true);

        if (!is_array($data)) {
            throw new RuntimeException('Invalid JSON response: ' . $response);
        }

        if (!empty($data['error'])) {
            throw new RuntimeException(
                'Bitrix24 error: ' . $data['error'] . ' / ' . ($data['error_description'] ?? '')
            );
        }

        return $data['result'] ?? [];
    }

    public function getDeal(int $dealId): array
    {
        return $this->call('crm.deal.get', ['id' => $dealId]);
    }

    public function getDealContacts(int $dealId): array
    {
        return $this->call('crm.deal.contact.items.get', ['id' => $dealId]);
    }

    public function getContact(int $contactId): array
    {
        return $this->call('crm.contact.get', ['id' => $contactId]);
    }

    public function extractPrimaryValue(array $items): ?string
    {
        foreach ($items as $item) {
            if (!empty($item['VALUE'])) {
                return (string)$item['VALUE'];
            }
        }

        return null;
    }
}
