<?php

declare(strict_types=1);

namespace App\Contracts;

interface NotificationClientInterface
{
    public function sendSms(string $phone, string $message): array;

    public function sendEmail(string $email, string $subject, string $message): array;
}
