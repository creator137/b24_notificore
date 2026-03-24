<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use Throwable;

final class UserInterface
{
    public static function checked(mixed $value): string
    {
        return self::toBool($value) ? 'checked' : '';
    }

    public static function selected(string $current, string $value): string
    {
        return $current === $value ? 'selected' : '';
    }

    public static function formatDateTime(?string $value): string
    {
        $value = trim((string)$value);

        if ($value === '') {
            return '—';
        }

        try {
            return (new DateTimeImmutable($value))->format('d.m.Y H:i');
        } catch (Throwable) {
            return $value;
        }
    }

    public static function formatPreview(string $value, int $limit = 120): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        if ($value === '') {
            return '—';
        }

        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        return mb_substr($value, 0, $limit - 1) . '…';
    }

    public static function sourceLabel(string $source): string
    {
        $source = strtolower(trim($source));

        return match ($source) {
            'manual_ui' => 'Тест из приложения',
            'manual' => 'Ручной запуск',
            'bitrix24' => 'Bitrix24',
            'system' => 'Система',
            'manual_sync' => 'Ручная синхронизация',
            default => $source === '' ? 'Не указан' : str_replace('_', ' ', $source),
        };
    }

    public static function statusMeta(string $status): array
    {
        $status = strtolower(trim($status));

        return match ($status) {
            'accepted' => ['label' => 'Принято провайдером', 'tone' => 'info', 'description' => 'Сообщение передано в Notificore и ожидает доставки.'],
            'delivered' => ['label' => 'Доставлено', 'tone' => 'ok', 'description' => 'Получатель получил сообщение.'],
            'undelivered' => ['label' => 'Не доставлено', 'tone' => 'error', 'description' => 'Провайдер сообщил, что доставка не состоялась.'],
            'failed', 'http_error' => ['label' => 'Ошибка отправки', 'tone' => 'error', 'description' => 'Во время отправки произошла ошибка.'],
            'rejected' => ['label' => 'Отклонено', 'tone' => 'error', 'description' => 'Провайдер отклонил сообщение.'],
            'expired' => ['label' => 'Срок истёк', 'tone' => 'warn', 'description' => 'Сообщение не было доставлено в отведённое время.'],
            'cancelled', 'canceled' => ['label' => 'Отменено', 'tone' => 'neutral', 'description' => 'Отправка сообщения была отменена.'],
            'mock_sent' => ['label' => 'Тестовый режим', 'tone' => 'neutral', 'description' => 'Сообщение было создано в режиме разработки.'],
            'queued', 'pending' => ['label' => 'В очереди', 'tone' => 'neutral', 'description' => 'Сообщение ждёт обновления статуса.'],
            default => ['label' => $status === '' ? 'Статус уточняется' : $status, 'tone' => 'neutral', 'description' => 'Провайдер ещё не прислал финальный статус.'],
        };
    }

    public static function humanizeError(string $message): string
    {
        $message = trim($message);

        if ($message === '') {
            return 'Произошла ошибка. Проверьте настройки и попробуйте ещё раз.';
        }

        $normalized = mb_strtolower($message);

        $known = [
            'notificore apikey is empty' => 'Укажите API-ключ Notificore.',
            'notificore baseurl is empty' => 'Укажите адрес API Notificore.',
            'notificore originator is empty' => 'Укажите имя отправителя.',
            'notificore originator must be 14 chars or less' => 'Имя отправителя должно быть не длиннее 14 символов.',
            'notificore validity must be from 1 to 72' => 'Срок жизни сообщения должен быть от 1 до 72 часов.',
            'notificore tariff must be from 0 to 9' => 'Тариф должен быть числом от 0 до 9.',
            'notificore basic auth requires login and password' => 'Для этого режима авторизации нужен логин и пароль.',
            'phone is empty after normalization' => 'Укажите корректный номер телефона.',
            'incoming payload does not contain phone or message' => 'Заполните номер телефона и текст сообщения.',
            'portal is not registered' => 'Приложение ещё не связано с порталом Bitrix24.',
            'invalid application_token' => 'Не удалось подтвердить запрос от Bitrix24.',
            'status sync is available only for real notificore mode' => 'Синхронизация статусов доступна только для рабочего подключения Notificore.',
            'message does not have provider identifiers' => 'У сообщения нет идентификатора провайдера, поэтому статус нельзя уточнить автоматически.',
            'provider returned unknown status' => 'Notificore вернул неопределённый статус.',
            'provider_message_id or reference is required' => 'Для обновления статуса нужен идентификатор сообщения или reference.',
            'email notificore api is not configured yet' => 'Отправка email в этой версии ещё не настроена.',
            'invalid callback token' => 'Некорректный токен callback-запроса.',
        ];

        if (isset($known[$normalized])) {
            return $known[$normalized];
        }

        if (str_contains($normalized, 'unauthorized') || str_contains($normalized, 'forbidden') || str_contains($normalized, 'api key') || str_contains($normalized, 'apikey')) {
            return 'Notificore отклонил авторизацию. Проверьте API-ключ.';
        }

        if (str_contains($normalized, 'originator') || str_contains($normalized, 'sender')) {
            return 'Проверьте имя отправителя. Оно должно быть разрешено у провайдера.';
        }

        if (str_contains($normalized, 'ssl') || str_contains($normalized, 'certificate')) {
            return 'Не удалось установить защищённое соединение с Notificore. Проверьте SSL на сервере.';
        }

        if (str_contains($normalized, 'could not resolve host') || str_contains($normalized, 'timed out') || str_contains($normalized, 'failed to connect') || str_contains($normalized, 'connection refused')) {
            return 'Сервер не смог подключиться к API Notificore. Проверьте адрес API и сетевую доступность.';
        }

        if (str_contains($normalized, '404') || str_contains($normalized, 'not found')) {
            return 'Указан неверный адрес API или технический путь запроса.';
        }

        return $message;
    }

    public static function connectionState(array $settings, ?array $checkResult = null, ?string $error = null): array
    {
        $hasApiKey = trim((string)($settings['api_key'] ?? '')) !== '';
        $hasOriginator = trim((string)($settings['originator'] ?? '')) !== '';
        $hasCallback = trim((string)($settings['status_callback_url'] ?? '')) !== '';

        $checklist = [
            ['label' => 'API-ключ', 'ready' => $hasApiKey],
            ['label' => 'Имя отправителя', 'ready' => $hasOriginator],
            ['label' => 'Адрес callback', 'ready' => $hasCallback],
        ];

        if ($error !== null && trim($error) !== '') {
            return [
                'tone' => self::connectionTone($error),
                'label' => self::connectionLabel($error),
                'summary' => self::humanizeError($error),
                'details' => 'Проверьте учётные данные и повторите проверку подключения.',
                'checklist' => $checklist,
            ];
        }

        if ($checkResult !== null) {
            if (($checkResult['success'] ?? false) === true) {
                $balance = trim((string)($checkResult['balance'] ?? ''));
                $currency = trim((string)($checkResult['currency'] ?? ''));
                $details = $balance !== ''
                    ? 'Баланс Notificore: ' . $balance . ($currency !== '' ? ' ' . $currency : '')
                    : 'Доступ к API подтверждён.';

                return [
                    'tone' => 'ok',
                    'label' => 'Подключено',
                    'summary' => 'Приложение успешно связалось с Notificore.',
                    'details' => $details,
                    'checklist' => $checklist,
                ];
            }

            $checkError = (string)($checkResult['error_message'] ?? '');

            return [
                'tone' => self::connectionTone($checkError),
                'label' => self::connectionLabel($checkError),
                'summary' => self::humanizeError($checkError),
                'details' => 'Проверка не подтвердила подключение. Исправьте настройки и повторите попытку.',
                'checklist' => $checklist,
            ];
        }

        if (!$hasApiKey || !$hasOriginator) {
            return [
                'tone' => 'warn',
                'label' => 'Требуется настройка',
                'summary' => 'Заполните API-ключ и имя отправителя, чтобы начать работу.',
                'details' => 'Остальные технические параметры обычно менять не требуется.',
                'checklist' => $checklist,
            ];
        }

        return [
            'tone' => 'info',
            'label' => 'Готово к проверке',
            'summary' => 'Основные параметры заполнены. Осталось проверить подключение.',
            'details' => 'После проверки можно выполнить тестовую отправку.',
            'checklist' => $checklist,
        ];
    }

    public static function technicalJson(mixed $value): string
    {
        return (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    private static function connectionLabel(string $message): string
    {
        $normalized = mb_strtolower(trim($message));

        if (str_contains($normalized, 'api key') || str_contains($normalized, 'apikey') || str_contains($normalized, 'unauthorized') || str_contains($normalized, 'forbidden')) {
            return 'Ошибка авторизации';
        }

        if (str_contains($normalized, 'originator') || str_contains($normalized, 'sender')) {
            return 'Некорректный отправитель';
        }

        if (str_contains($normalized, 'ssl') || str_contains($normalized, 'certificate')) {
            return 'Ошибка SSL';
        }

        return 'Ошибка запроса';
    }

    private static function connectionTone(string $message): string
    {
        $normalized = mb_strtolower(trim($message));
        return str_contains($normalized, 'originator') || str_contains($normalized, 'sender') ? 'warn' : 'error';
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(mb_strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }
}