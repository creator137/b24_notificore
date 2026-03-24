<?php

declare(strict_types=1);

namespace App\Support;

final class BitrixRequest
{
    public static function hasPortalData(array $payload): bool
    {
        return self::resolveMemberId($payload) !== '' || self::resolveDomain($payload) !== '';
    }

    public static function extractPortal(array $payload): array
    {
        $auth = self::extractAuth($payload);
        $expiresIn = (int)(
            $payload['AUTH_EXPIRES']
            ?? $auth['expires']
            ?? $auth['expires_in']
            ?? 0
        );

        return [
            'member_id' => self::resolveMemberId($payload),
            'domain' => self::resolveDomain($payload),
            'access_token' => (string)(
                $payload['AUTH_ID']
                ?? $auth['access_token']
                ?? ''
            ),
            'refresh_token' => (string)(
                $payload['REFRESH_ID']
                ?? $auth['refresh_token']
                ?? ''
            ),
            'application_token' => self::resolveApplicationToken($payload),
            'expires_at' => $expiresIn > 0 ? time() + $expiresIn : 0,
            'installed_at' => date('c'),
            'raw' => $payload,
        ];
    }

    public static function resolveMemberId(array $payload): string
    {
        $auth = self::extractAuth($payload);

        return trim((string)(
            $payload['member_id']
            ?? $payload['MEMBER_ID']
            ?? $auth['member_id']
            ?? ''
        ));
    }

    public static function resolveDomain(array $payload): string
    {
        $auth = self::extractAuth($payload);

        return trim((string)(
            $payload['DOMAIN']
            ?? $payload['domain']
            ?? $auth['domain']
            ?? ''
        ));
    }

    public static function resolveApplicationToken(array $payload): string
    {
        $auth = self::extractAuth($payload);

        return trim((string)(
            $payload['application_token']
            ?? $auth['application_token']
            ?? ''
        ));
    }

    public static function resolvePhone(array $payload): string
    {
        return trim((string)(
            $payload['message_to']
            ?? $payload['PHONE']
            ?? $payload['phone']
            ?? $payload['msisdn']
            ?? ''
        ));
    }

    public static function resolveMessageBody(array $payload): string
    {
        return trim((string)(
            $payload['message_body']
            ?? $payload['MESSAGE']
            ?? $payload['message']
            ?? $payload['body']
            ?? ''
        ));
    }

    public static function resolveBitrixMessageId(array $payload): string
    {
        return trim((string)(
            $payload['message_id']
            ?? $payload['MESSAGE_ID']
            ?? ''
        ));
    }

    public static function resolveProviderMessageId(array $payload): string
    {
        return trim((string)(
            $payload['provider_message_id']
            ?? $payload['message_id']
            ?? $payload['id']
            ?? ''
        ));
    }

    public static function resolveProviderReference(array $payload): string
    {
        return trim((string)($payload['reference'] ?? ''));
    }

    public static function resolveStatus(array $payload): string
    {
        return trim((string)(
            $payload['status']
            ?? $payload['delivery_status']
            ?? $payload['message_status']
            ?? 'unknown'
        ));
    }

    private static function extractAuth(array $payload): array
    {
        $auth = $payload['auth'] ?? $payload['AUTH'] ?? [];

        return is_array($auth) ? $auth : [];
    }
}
