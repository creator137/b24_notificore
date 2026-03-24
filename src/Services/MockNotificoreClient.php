<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\NotificationClientInterface;

final class MockNotificoreClient implements NotificationClientInterface
{
    public function sendSms(string $phone, string $message): array
    {
        if ($phone === '') {
            return [
                'success' => false,
                'status' => 'no_phone',
                'provider_message_id' => null,
                'channel' => 'sms',
            ];
        }

        return [
            'success' => true,
            'status' => 'mock_sent',
            'provider_message_id' => 'mock-sms-' . time(),
            'channel' => 'sms',
            'phone' => $phone,
            'message_preview' => $message,
        ];
    }

    public function sendEmail(string $email, string $subject, string $message): array
    {
        if ($email === '') {
            return [
                'success' => false,
                'status' => 'no_email',
                'provider_message_id' => null,
                'channel' => 'email',
            ];
        }

        return [
            'success' => true,
            'status' => 'mock_sent',
            'provider_message_id' => 'mock-email-' . time(),
            'channel' => 'email',
            'email' => $email,
            'subject' => $subject,
            'message_preview' => $message,
        ];
    }
}
