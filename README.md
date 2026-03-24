# b24_notificore

Рабочее PHP-приложение для Bitrix24 под интеграцию с Notificore.

Что умеет сейчас:
- установка приложения и регистрация SMS-провайдера в Bitrix24
- UI настроек в `public/app.php`
- ручная тестовая отправка SMS
- обработчик отправки из Bitrix24 в `public/sms_handler.php`
- callback по статусам в `public/status_callback.php`
- ручная синхронизация pending-статусов
- хранение порталов, настроек и истории сообщений в БД
- миграция legacy JSON-данных в БД при первом запуске

## Архитектура

Основные entrypoints:
- `public/install.php` — установка приложения в Bitrix24
- `public/app.php` — настройки и тестирование
- `public/sms_handler.php` — обработчик отправки SMS из Bitrix24
- `public/status_callback.php` — callback / обновление статусов
- `public/health.php` — healthcheck
- `bin/sync_statuses.php` — CLI-синхронизация статусов для cron

Хранилище по умолчанию:
- `SQLite` в `storage/notificore.sqlite`
- при первом запуске старые `storage/*.json` автоматически импортируются в БД
- при необходимости можно переключиться на MySQL через `.env`

## Notificore

Клиент настроен под реальный REST API Notificore.

Ключевые параметры:
- базовый URL: `https://api.notificore.ru`
- отправка SMS: `/rest/sms/create`
- баланс: `/rest/common/balance`
- авторизация: `X-API-KEY: <key>`
- content type: `text/json; charset=utf-8`

По умолчанию для real-режима используются:
- `auth_mode=x_api_key`
- `api_key_header=X-API-KEY`

## Быстрый запуск локально

```bash
composer install
cp .env.example .env
php -S 127.0.0.1:8000 -t public
```

Открыть:
- `http://127.0.0.1:8000/install.php`
- затем `http://127.0.0.1:8000/app.php`

## Настройка `.env`

Минимально важно заполнить:

```dotenv
APP_BASE_URL=https://your-domain.tld/notificore/public

DB_DRIVER=sqlite
DB_DATABASE=storage/notificore.sqlite

B24_SENDER_CODE=notificore_sms
B24_SENDER_NAME=Notificore SMS
B24_APP_CLIENT_ID=
B24_APP_CLIENT_SECRET=

NOTIFICORE_MODE=real
NOTIFICORE_BASE_URL=https://api.notificore.ru
NOTIFICORE_API_KEY=
NOTIFICORE_AUTH_MODE=x_api_key
NOTIFICORE_API_KEY_HEADER=X-API-KEY
NOTIFICORE_ORIGINATOR=
```

Опционально:
- `STATUS_CALLBACK_SECRET` — защита callback URL токеном
- `DEV_MODE=true` — оставить dev-форму установки
- `DEV_INSTALL_MOCK=true` — не ходить в реальный Bitrix REST при локальной установке

## Порядок установки в Bitrix24

1. Разместить проект на внешнем URL с доступом по HTTP/HTTPS.
2. Указать `APP_BASE_URL` так, чтобы он смотрел на папку `public`.
3. В карточке приложения Bitrix24 использовать URL установки:
   - `https://your-domain.tld/notificore/public/install.php`
4. После установки открыть:
   - `https://your-domain.tld/notificore/public/app.php`
5. Сохранить настройки Notificore.
6. Проверить баланс кнопкой «Проверить Notificore».
7. Выполнить тестовую отправку.

## Cron / добор статусов

Если callback от Notificore не используется или нужен резервный канал обновления статусов, можно запускать:

```bash
php bin/sync_statuses.php --limit=20
```

Пример для одного портала:

```bash
php bin/sync_statuses.php --member=your_member_id --limit=20
```

## Shared hosting / VPS

Для shared hosting удобнее оставить SQLite:
- не нужен отдельный сервер БД
- переносится одним каталогом
- достаточно прав на запись в `storage/` и `logs/`

Для VPS можно перейти на MySQL:

```dotenv
DB_DRIVER=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=notificore
DB_USER=notificore
DB_PASSWORD=secret
DB_CHARSET=utf8mb4
```

## Что проверить после деплоя

- `public/health.php` открывается без ошибок
- `public/install.php` доступен извне
- `APP_BASE_URL` совпадает с реальным URL папки `public`
- `storage/` и `logs/` доступны на запись
- в `public/app.php` сохраняются настройки
- тестовая отправка создаёт запись в БД
- callback или `bin/sync_statuses.php` обновляют статусы

## Безопасность

- секреты хранить только в `.env`
- `.env`, `logs/` и runtime БД не коммитить
- для callback рекомендуется задать `STATUS_CALLBACK_SECRET`
- для реального обновления статусов в Bitrix24 желательно заполнить `B24_APP_CLIENT_ID` и `B24_APP_CLIENT_SECRET`, чтобы приложение могло refresh-ить OAuth токены
