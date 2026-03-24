<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Application\HandleSmsAction;
use App\Application\SyncPendingStatusesAction;
use App\Services\NotificationClientFactory;
use App\Services\NotificoreClient;
use App\Support\BitrixRequest;
use App\Support\HttpRequest;
use App\Support\UserInterface;

function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function badgeClass(string $tone): string
{
    return match ($tone) {
        'ok' => 'badge ok',
        'error' => 'badge error',
        'warn' => 'badge warn',
        'info' => 'badge info',
        default => 'badge neutral',
    };
}

function alertClass(string $tone): string
{
    return match ($tone) {
        'ok' => 'alert ok',
        'error' => 'alert error',
        'warn' => 'alert warn',
        default => 'alert info',
    };
}

function normalizePortalSettings(array $settings, string $defaultStatusCallbackUrl): array
{
    $settings['mode'] = 'real';
    $settings['base_url'] = trim((string)($settings['base_url'] ?? '')) !== '' ? trim((string)$settings['base_url']) : 'https://api.notificore.ru';
    $settings['auth_mode'] = trim((string)($settings['auth_mode'] ?? '')) !== '' ? trim((string)$settings['auth_mode']) : 'x_api_key';
    $settings['request_format'] = trim((string)($settings['request_format'] ?? '')) !== '' ? trim((string)$settings['request_format']) : 'json';
    $settings['api_key_header'] = trim((string)($settings['api_key_header'] ?? '')) !== '' ? trim((string)$settings['api_key_header']) : 'X-API-KEY';
    $settings['sms_send_path'] = trim((string)($settings['sms_send_path'] ?? '')) !== '' ? trim((string)$settings['sms_send_path']) : '/rest/sms/create';
    $settings['email_send_path'] = trim((string)($settings['email_send_path'] ?? '')) !== '' ? trim((string)$settings['email_send_path']) : '/email/send';
    $settings['balance_path'] = trim((string)($settings['balance_path'] ?? '')) !== '' ? trim((string)$settings['balance_path']) : '/rest/common/balance';
    $settings['sms_status_path'] = trim((string)($settings['sms_status_path'] ?? '')) !== '' ? trim((string)$settings['sms_status_path']) : '/rest/sms/{id}';
    $settings['sms_status_reference_path'] = trim((string)($settings['sms_status_reference_path'] ?? '')) !== '' ? trim((string)$settings['sms_status_reference_path']) : '/rest/sms/reference/{reference}';
    $settings['verify_ssl'] = ((string)($settings['verify_ssl'] ?? '1')) === '0' ? '0' : '1';
    $settings['is_2way'] = ((string)($settings['is_2way'] ?? '0')) === '1' ? '1' : '0';
    $settings['status_callback_url'] = trim((string)($settings['status_callback_url'] ?? '')) !== '' ? trim((string)$settings['status_callback_url']) : $defaultStatusCallbackUrl;

    return $settings;
}

function mergePortalSettings(array $payload, array $currentSettings, string $defaultStatusCallbackUrl): array
{
    $password = array_key_exists('password', $payload)
        ? trim((string)$payload['password'])
        : (string)($currentSettings['password'] ?? '');
    $statusCallbackUrl = trim((string)($payload['status_callback_url'] ?? ''));

    return normalizePortalSettings(array_replace($currentSettings, [
        'mode' => 'real',
        'base_url' => trim((string)($payload['base_url'] ?? (string)($currentSettings['base_url'] ?? ''))),
        'login' => trim((string)($payload['login'] ?? (string)($currentSettings['login'] ?? ''))),
        'password' => $password !== '' ? $password : (string)($currentSettings['password'] ?? ''),
        'project_id' => trim((string)($payload['project_id'] ?? (string)($currentSettings['project_id'] ?? ''))),
        'api_key' => trim((string)($payload['api_key'] ?? (string)($currentSettings['api_key'] ?? ''))),
        'auth_mode' => trim((string)($payload['auth_mode'] ?? (string)($currentSettings['auth_mode'] ?? 'x_api_key'))),
        'request_format' => trim((string)($payload['request_format'] ?? (string)($currentSettings['request_format'] ?? 'json'))),
        'sms_send_path' => trim((string)($payload['sms_send_path'] ?? (string)($currentSettings['sms_send_path'] ?? '/rest/sms/create'))),
        'email_send_path' => trim((string)($payload['email_send_path'] ?? (string)($currentSettings['email_send_path'] ?? '/email/send'))),
        'api_key_header' => trim((string)($payload['api_key_header'] ?? (string)($currentSettings['api_key_header'] ?? 'X-API-KEY'))),
        'verify_ssl' => isset($payload['verify_ssl']) ? '1' : '0',
        'originator' => trim((string)($payload['originator'] ?? (string)($currentSettings['originator'] ?? ''))),
        'validity' => trim((string)($payload['validity'] ?? (string)($currentSettings['validity'] ?? ''))),
        'tariff' => trim((string)($payload['tariff'] ?? (string)($currentSettings['tariff'] ?? ''))),
        'is_2way' => isset($payload['is_2way']) ? '1' : '0',
        'balance_path' => trim((string)($payload['balance_path'] ?? (string)($currentSettings['balance_path'] ?? '/rest/common/balance'))),
        'sms_status_path' => trim((string)($payload['sms_status_path'] ?? (string)($currentSettings['sms_status_path'] ?? '/rest/sms/{id}'))),
        'sms_status_reference_path' => trim((string)($payload['sms_status_reference_path'] ?? (string)($currentSettings['sms_status_reference_path'] ?? '/rest/sms/reference/{reference}'))),
        'status_callback_url' => $statusCallbackUrl !== '' ? $statusCallbackUrl : $defaultStatusCallbackUrl,
    ]), $defaultStatusCallbackUrl);
}

$container = app_container();
$portalRepository = $container->portalRepository();
$settingsRepository = $container->settingsRepository();
$messageRepository = $container->messageRepository();
$payload = HttpRequest::payload();

$incomingPortal = BitrixRequest::extractPortal($payload);
if ((string)($incomingPortal['member_id'] ?? '') !== '' && (string)($incomingPortal['domain'] ?? '') !== '' && (string)($incomingPortal['access_token'] ?? '') !== '') {
    $portalRepository->save($incomingPortal);
}

$memberId = trim((string)($_GET['member_id'] ?? BitrixRequest::resolveMemberId($payload) ?? ''));
$domain = BitrixRequest::resolveDomain($payload);

if ($memberId !== '') {
    $portal = $portalRepository->findByMemberId($memberId);
} elseif ($domain !== '') {
    $portal = $portalRepository->findByDomain($domain);
} else {
    $portal = $portalRepository->findFirst();
}

$feedbackMessage = null;
$feedbackTone = 'ok';
$testResult = null;
$connectionResult = null;
$connectionError = null;
$syncResult = null;
$defaultStatusCallbackUrl = $container->config()->defaultStatusCallbackUrl();
$storedSettings = [];
$settings = normalizePortalSettings(NotificationClientFactory::defaults(), $defaultStatusCallbackUrl);
$legacyMockMode = false;
$testPhoneValue = trim((string)($payload['test_phone'] ?? ''));
$testMessageValue = trim((string)($payload['test_message'] ?? '')) ?: 'Тестовое сообщение из приложения Bitrix24 + Notificore.';

if ($portal) {
    $memberId = (string)$portal['member_id'];
    $storedSettings = $settingsRepository->findByMemberId($memberId);
    $settings = normalizePortalSettings(array_replace(NotificationClientFactory::defaults(), $storedSettings), $defaultStatusCallbackUrl);
    $legacyMockMode = strtolower((string)($storedSettings['mode'] ?? '')) === 'mock';

    if (HttpRequest::method() === 'POST') {
        $formType = (string)($payload['form_type'] ?? '');

        try {
            if ($formType === 'settings') {
                $settings = mergePortalSettings($payload, $settings, $defaultStatusCallbackUrl);
                $settingsRepository->save($memberId, $settings);
                $storedSettings = $settingsRepository->findByMemberId($memberId);
                $settings = normalizePortalSettings(array_replace(NotificationClientFactory::defaults(), $storedSettings), $defaultStatusCallbackUrl);
                $legacyMockMode = false;
                $feedbackMessage = 'Настройки сохранены. Теперь можно проверить подключение и отправить тестовое сообщение.';
            }

            if ($formType === 'connection_check') {
                $client = NotificationClientFactory::makeFromSettings(array_replace($settings, ['mode' => 'real']));
                if (!$client instanceof NotificoreClient) {
                    throw new RuntimeException('Unsupported notificore mode: real');
                }
                $connectionResult = $client->getBalance();
                if (($connectionResult['success'] ?? false) === true) {
                    $feedbackMessage = 'Подключение к Notificore подтверждено.';
                }
            }

            if ($formType === 'test_send') {
                $action = new HandleSmsAction(
                    portalRepository: $portalRepository,
                    settingsRepository: $settingsRepository,
                    messageRepository: $messageRepository,
                    logger: $container->logger(),
                    bitrixStatusUpdater: $container->bitrixStatusUpdater(),
                );

                $testResult = $action([
                    'member_id' => $memberId,
                    'phone' => (string)($payload['test_phone'] ?? ''),
                    'message' => (string)($payload['test_message'] ?? ''),
                    'message_id' => 'manual-test-' . time(),
                    'source' => 'manual_ui',
                    'is_test' => true,
                ]);

                if (($testResult['success'] ?? false) === true) {
                    $feedbackMessage = 'Тестовое сообщение отправлено в Notificore.';
                } else {
                    $feedbackTone = 'error';
                    $feedbackMessage = UserInterface::humanizeError((string)($testResult['data']['error_message'] ?? ''));
                }
            }

            if ($formType === 'sync_statuses') {
                $syncAction = new SyncPendingStatusesAction(
                    settingsRepository: $settingsRepository,
                    messageRepository: $messageRepository,
                    logger: $container->logger(),
                    bitrixStatusUpdater: $container->bitrixStatusUpdater(),
                );
                $syncResult = $syncAction($memberId, max(1, (int)($payload['sync_limit'] ?? 20)));
                $feedbackMessage = ($syncResult['updated_count'] ?? 0) > 0
                    ? 'Статусы сообщений обновлены.'
                    : 'Синхронизация завершена.';
            }
        } catch (Throwable $e) {
            $feedbackTone = 'error';
            $feedbackMessage = UserInterface::humanizeError($e->getMessage());
            if ($formType === 'connection_check') {
                $connectionError = $e->getMessage();
            }
            if ($formType === 'test_send') {
                $testResult = ['success' => false, 'data' => ['error_message' => $e->getMessage()]];
            }
        }
    }
}

$connectionState = UserInterface::connectionState($settings, $connectionResult, $connectionError);
$messages = $portal ? $messageRepository->recent($memberId, 50) : [];
$appUrl = $container->config()->handlerUrl('app.php');
$installUrl = $container->config()->handlerUrl('install.php');
$smsHandlerUrl = $container->config()->handlerUrl('sms_handler.php');
$statusCallbackUrl = (string)($settings['status_callback_url'] ?? $defaultStatusCallbackUrl);
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Notificore для Bitrix24</title>
    <style>
        :root{--bg:#f4efe6;--panel:#fffdf8;--ink:#142230;--muted:#637083;--line:#decfb8;--accent:#0f766e;--accent-soft:#daf5ef;--warn:#a66300;--warn-soft:#fff0c8;--danger:#b42318;--danger-soft:#fee4e2;--info:#026aa2;--info-soft:#dff1fb;--neutral:#eef2f6;--shadow:0 24px 56px rgba(83,62,26,.14)}
        *{box-sizing:border-box} body{margin:0;font-family:"Trebuchet MS","Segoe UI",sans-serif;color:var(--ink);background:radial-gradient(circle at top right,rgba(15,118,110,.16),transparent 28%),radial-gradient(circle at bottom left,rgba(166,99,0,.1),transparent 32%),linear-gradient(180deg,#fbf7f0 0%,var(--bg) 100%)}
        .page{max-width:1260px;margin:0 auto;padding:28px 18px 42px}.hero,.layout,.grid2,.grid3,.meta{display:grid;gap:18px}.hero{grid-template-columns:minmax(0,1.35fr) minmax(280px,.8fr);margin-bottom:20px}.layout{grid-template-columns:minmax(0,1.35fr) minmax(320px,.85fr)}.grid2,.meta{grid-template-columns:repeat(2,minmax(0,1fr))}.grid3{grid-template-columns:repeat(3,minmax(0,1fr))}.stack{display:grid;gap:20px}
        .card,.hero-main,.hero-side{background:var(--panel);border:1px solid var(--line);border-radius:24px;box-shadow:var(--shadow);padding:22px}.hero-main{background:linear-gradient(135deg,rgba(15,118,110,.08),rgba(255,248,238,.92)),var(--panel)}
        .eyebrow,.badge{display:inline-flex;align-items:center;justify-content:center;padding:6px 12px;border-radius:999px;font-size:12px;font-weight:700}.eyebrow{background:rgba(15,118,110,.12);color:#0d5d58;text-transform:uppercase;letter-spacing:.04em}.badge.ok{background:#dcfae6;color:#067647}.badge.error{background:#fee4e2;color:#b42318}.badge.warn{background:#fff0c8;color:#9a6700}.badge.info{background:#dff1fb;color:#026aa2}.badge.neutral{background:var(--neutral);color:#475467}
        h1{margin:16px 0 12px;font-size:36px;line-height:1.03}h2{margin:0 0 10px;font-size:24px}h3{margin:0 0 8px;font-size:18px}p{margin:0;line-height:1.58}.subtle,.hint,.muted{color:var(--muted)}.hint,.muted{font-size:13px}
        .header{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:16px}.actions,.hero-actions{display:flex;flex-wrap:wrap;gap:10px}.hero-actions{margin-top:18px}
        .alert,.box,.tile,.readonly,.check{border:1px solid var(--line);border-radius:18px;padding:14px}.alert{margin-bottom:20px}.alert strong{display:block;margin-bottom:4px}.alert.ok{background:var(--accent-soft);border-color:rgba(15,118,110,.2)}.alert.error{background:var(--danger-soft);border-color:rgba(180,35,24,.2);color:var(--danger)}.alert.warn{background:var(--warn-soft)}.alert.info{background:var(--info-soft)}
        label{display:block;margin-top:14px;font-weight:700}input,select,textarea{width:100%;margin-top:6px;padding:12px 13px;border:1px solid var(--line);border-radius:14px;background:#fff;color:var(--ink);font:inherit}textarea{min-height:130px;resize:vertical}.checkrow{display:flex;gap:10px;align-items:center;margin-top:14px;font-weight:600}.checkrow input{width:auto;margin:0}
        button,.linkbtn{display:inline-flex;align-items:center;justify-content:center;min-height:46px;padding:12px 16px;border-radius:14px;border:0;background:var(--accent);color:#fff;font:inherit;font-weight:700;text-decoration:none;cursor:pointer}button.secondary,.linkbtn{background:#213547}button.warm{background:var(--warn)}
        .readonly{background:#f8f4ec;border-style:dashed;margin-top:14px}code,pre{background:#f6efe3;border-radius:14px}code{display:block;margin-top:8px;padding:10px 12px;white-space:pre-wrap;word-break:break-word}pre{margin:10px 0 0;padding:14px;overflow:auto;white-space:pre-wrap;word-break:break-word}
        details{margin-top:16px;padding-top:14px;border-top:1px solid #ebe1d2}details summary{cursor:pointer;font-weight:700;list-style:none}details summary::-webkit-details-marker{display:none}.checklist{display:grid;gap:10px;margin-top:14px}.check{display:flex;justify-content:space-between;align-items:center;gap:12px;background:#fff9f1}.history{overflow-x:auto;margin-top:10px}table{width:100%;min-width:860px;border-collapse:collapse}th,td{padding:14px 10px;border-bottom:1px solid #ebe1d2;text-align:left;vertical-align:top;font-size:14px}th{color:var(--muted);font-size:12px;text-transform:uppercase;letter-spacing:.04em}.vstack{display:grid;gap:4px}
        @media (max-width:1080px){.hero,.layout{grid-template-columns:1fr}} @media (max-width:760px){.page{padding:16px 14px 34px}h1{font-size:30px}.grid2,.grid3,.meta{grid-template-columns:1fr}.card,.hero-main,.hero-side{padding:18px}}
    </style>
    <script src="https://api.bitrix24.com/api/v1/"></script>
</head>
<body>
<div class="page">    <section class="hero">
        <div class="hero-main">
            <span class="eyebrow">Bitrix24 + Notificore</span>
            <h1>SMS-уведомления для пользователей Bitrix24</h1>
            <p class="subtle">Приложение сохраняет подключение к порталу, отправляет SMS через Notificore, отслеживает статусы доставки и показывает историю сообщений без лишних технических деталей.</p>
            <div class="hero-actions">
                <span class="<?= e(badgeClass($connectionState['tone'])) ?>"><?= e($connectionState['label']) ?></span>
                <?php if ($portal): ?><span class="badge neutral">Портал: <?= e((string)($portal['domain'] ?? '')) ?></span><?php endif; ?>
            </div>
        </div>
        <aside class="hero-side">
            <h3>Состояние подключения</h3>
            <p class="subtle"><?= e($connectionState['summary']) ?></p>
            <div class="box">
                <span class="<?= e(badgeClass($connectionState['tone'])) ?>"><?= e($connectionState['label']) ?></span>
                <p class="muted" style="margin-top:8px;"><?= e($connectionState['details']) ?></p>
            </div>
            <?php if ($portal): ?><div class="box"><span class="muted">Адрес приложения</span><div style="margin-top:8px;font-weight:700;"><?= e($appUrl) ?></div></div><?php endif; ?>
        </aside>
    </section>

    <?php if ($feedbackMessage !== null): ?>
        <div class="<?= e(alertClass($feedbackTone)) ?>"><strong><?= e($feedbackTone === 'error' ? 'Нужно внимание' : 'Готово') ?></strong><div><?= e($feedbackMessage) ?></div></div>
    <?php endif; ?>

    <?php if (!$portal): ?>
        <section class="card">
            <div class="header">
                <div>
                    <h2>Приложение ещё не связано с порталом</h2>
                    <p class="subtle">Откройте установочный URL из Bitrix24, чтобы сохранить портал и подготовить рабочее окружение приложения.</p>
                </div>
                <span class="badge warn">Требуется установка</span>
            </div>
            <div class="readonly"><strong>Установочный URL</strong><code><?= e($installUrl) ?></code></div>
            <div class="readonly"><strong>URL приложения</strong><code><?= e($appUrl) ?></code></div>
            <details>
                <summary>Техническая информация</summary>
                <pre><?= e(UserInterface::technicalJson(['install_url' => $installUrl, 'app_url' => $appUrl, 'sms_handler_url' => $smsHandlerUrl])) ?></pre>
            </details>
        </section>
    <?php else: ?>
        <div class="layout">
            <div class="stack">
                <section class="card">
                    <div class="header">
                        <div>
                            <h2>Подключение Notificore</h2>
                            <p class="subtle">Обычно достаточно указать API-ключ и имя отправителя. Остальные параметры уже предзаполнены и скрыты ниже.</p>
                        </div>
                        <span class="badge info">Рабочий режим</span>
                    </div>

                    <?php if ($legacyMockMode): ?>
                        <div class="alert warn"><strong>Обнаружен старый тестовый режим</strong><div>После сохранения формы приложение будет использовать только реальный Notificore.</div></div>
                    <?php endif; ?>

                    <form method="post">
                        <input type="hidden" name="form_type" value="settings">
                        <div class="grid2">
                            <div>
                                <label for="api_key">API-ключ Notificore</label>
                                <input id="api_key" type="password" name="api_key" value="<?= e((string)($settings['api_key'] ?? '')) ?>" autocomplete="off">
                                <div class="hint">Используется для авторизации запросов через заголовок X-API-KEY.</div>
                            </div>
                            <div>
                                <label for="originator">Имя отправителя</label>
                                <input id="originator" type="text" name="originator" maxlength="14" value="<?= e((string)($settings['originator'] ?? '')) ?>">
                                <div class="hint">Показывается получателю и должно быть разрешено у провайдера.</div>
                            </div>
                        </div>
                        <div class="grid2">
                            <div>
                                <label for="validity">Срок жизни SMS, часы</label>
                                <input id="validity" type="number" min="1" max="72" name="validity" value="<?= e((string)($settings['validity'] ?? '')) ?>" placeholder="Например, 72">
                            </div>
                            <div>
                                <label for="project_id">Идентификатор проекта</label>
                                <input id="project_id" type="text" name="project_id" value="<?= e((string)($settings['project_id'] ?? '')) ?>" placeholder="Необязательно">
                            </div>
                        </div>
                        <div class="readonly">
                            <strong>Адрес callback для статусов доставки</strong>
                            <div class="hint">Формируется автоматически и передаётся провайдеру при отправке.</div>
                            <code><?= e($statusCallbackUrl) ?></code>
                        </div>
                        <details>
                            <summary>Расширенные настройки</summary>
                            <div class="grid2">
                                <div><label for="base_url">Базовый URL API</label><input id="base_url" type="text" name="base_url" value="<?= e((string)($settings['base_url'] ?? '')) ?>"></div>
                                <div><label for="api_key_header">Имя заголовка API-ключа</label><input id="api_key_header" type="text" name="api_key_header" value="<?= e((string)($settings['api_key_header'] ?? 'X-API-KEY')) ?>"></div>
                            </div>
                            <div class="grid2">
                                <div>
                                    <label for="auth_mode">Способ авторизации</label>
                                    <select id="auth_mode" name="auth_mode">
                                        <option value="x_api_key" <?= e(UserInterface::selected((string)($settings['auth_mode'] ?? 'x_api_key'), 'x_api_key')) ?>>X-API-KEY</option>
                                        <option value="header" <?= e(UserInterface::selected((string)($settings['auth_mode'] ?? 'x_api_key'), 'header')) ?>>Пользовательский заголовок</option>
                                        <option value="basic" <?= e(UserInterface::selected((string)($settings['auth_mode'] ?? 'x_api_key'), 'basic')) ?>>Basic Auth</option>
                                        <option value="none" <?= e(UserInterface::selected((string)($settings['auth_mode'] ?? 'x_api_key'), 'none')) ?>>Без авторизации</option>
                                    </select>
                                </div>
                                <div>
                                    <label for="request_format">Формат запроса</label>
                                    <select id="request_format" name="request_format">
                                        <option value="json" <?= e(UserInterface::selected((string)($settings['request_format'] ?? 'json'), 'json')) ?>>JSON</option>
                                        <option value="form" <?= e(UserInterface::selected((string)($settings['request_format'] ?? 'json'), 'form')) ?>>FORM</option>
                                    </select>
                                </div>
                            </div>
                            <div class="grid2">
                                <div><label for="login">Логин</label><input id="login" type="text" name="login" value="<?= e((string)($settings['login'] ?? '')) ?>"></div>
                                <div><label for="password">Пароль</label><input id="password" type="password" name="password" value="" autocomplete="off" placeholder="Оставьте пустым, чтобы не менять"></div>
                            </div>
                            <div class="grid2">
                                <div><label for="tariff">Тариф</label><input id="tariff" type="number" min="0" max="9" name="tariff" value="<?= e((string)($settings['tariff'] ?? '')) ?>"></div>
                                <div><label for="status_callback_url">Переопределение callback</label><input id="status_callback_url" type="text" name="status_callback_url" value="<?= e($statusCallbackUrl) ?>"></div>
                            </div>
                            <div class="grid2">
                                <div><label for="sms_send_path">Путь отправки SMS</label><input id="sms_send_path" type="text" name="sms_send_path" value="<?= e((string)($settings['sms_send_path'] ?? '/rest/sms/create')) ?>"></div>
                                <div><label for="balance_path">Путь проверки баланса</label><input id="balance_path" type="text" name="balance_path" value="<?= e((string)($settings['balance_path'] ?? '/rest/common/balance')) ?>"></div>
                            </div>
                            <div class="grid2">
                                <div><label for="sms_status_path">Путь статуса по ID</label><input id="sms_status_path" type="text" name="sms_status_path" value="<?= e((string)($settings['sms_status_path'] ?? '/rest/sms/{id}')) ?>"></div>
                                <div><label for="sms_status_reference_path">Путь статуса по reference</label><input id="sms_status_reference_path" type="text" name="sms_status_reference_path" value="<?= e((string)($settings['sms_status_reference_path'] ?? '/rest/sms/reference/{reference}')) ?>"></div>
                            </div>
                            <div class="grid2">
                                <div><label for="email_send_path">Путь email-отправки</label><input id="email_send_path" type="text" name="email_send_path" value="<?= e((string)($settings['email_send_path'] ?? '/email/send')) ?>"></div>
                                <div>
                                    <label class="checkrow"><input type="checkbox" name="is_2way" value="1" <?= e(UserInterface::checked((string)($settings['is_2way'] ?? '0'))) ?>>Включить 2WAY SMS</label>
                                    <label class="checkrow"><input type="checkbox" name="verify_ssl" value="1" <?= e(UserInterface::checked((string)($settings['verify_ssl'] ?? '1'))) ?>>Проверять SSL-сертификаты</label>
                                </div>
                            </div>
                        </details>
                        <div class="actions"><button type="submit">Сохранить настройки</button></div>
                    </form>
                </section>

                <section class="card">
                    <div class="header">
                        <div>
                            <h2>Тестовая отправка</h2>
                            <p class="subtle">Отправьте сообщение вручную, чтобы быстро проверить связку Bitrix24 и Notificore.</p>
                        </div>
                        <span class="badge neutral">Ручная проверка</span>
                    </div>
                    <form method="post">
                        <input type="hidden" name="form_type" value="test_send">
                        <label for="test_phone">Номер телефона</label>
                        <input id="test_phone" type="text" name="test_phone" placeholder="+7 (999) 000-11-22" value="<?= e($testPhoneValue) ?>">
                        <div class="hint">Можно вводить номер в любом привычном формате.</div>
                        <label for="test_message">Текст сообщения</label>
                        <textarea id="test_message" name="test_message"><?= e($testMessageValue) ?></textarea>
                        <div class="actions"><button type="submit">Отправить тестовое сообщение</button></div>
                    </form>

                    <?php if ($testResult !== null): ?>
                        <?php $testData = (array)($testResult['data'] ?? []); $testStatus = UserInterface::statusMeta((string)($testData['status'] ?? (($testResult['success'] ?? false) ? 'accepted' : 'failed'))); ?>
                        <div class="box" style="margin-top:16px;">
                            <div class="header" style="margin-bottom:10px;">
                                <div>
                                    <h3><?= e(($testResult['success'] ?? false) ? 'Результат отправки' : 'Не удалось отправить сообщение') ?></h3>
                                    <p class="subtle"><?= e(($testResult['success'] ?? false) ? 'Notificore принял сообщение и вернул идентификатор отслеживания.' : UserInterface::humanizeError((string)($testData['error_message'] ?? ''))) ?></p>
                                </div>
                                <span class="<?= e(badgeClass((string)$testStatus['tone'])) ?>"><?= e((string)$testStatus['label']) ?></span>
                            </div>
                            <div class="grid2">
                                <div class="tile"><span class="muted">Номер получателя</span><div style="margin-top:8px;font-weight:700;"><?= e((string)($testData['phone'] ?? $testPhoneValue ?: '—')) ?></div></div>
                                <div class="tile"><span class="muted">ID сообщения у провайдера</span><div style="margin-top:8px;font-weight:700;"><?= e((string)($testData['provider_message_id'] ?? '—')) ?></div></div>
                            </div>
                            <details><summary>Техническая информация</summary><pre><?= e(UserInterface::technicalJson($testResult)) ?></pre></details>
                        </div>
                    <?php endif; ?>
                </section>
            </div>            <div class="stack">
                <section class="card">
                    <div class="header">
                        <div>
                            <h2>Портал и маршруты</h2>
                            <p class="subtle">Основные адреса формируются автоматически и не требуют ручного ввода внутри Bitrix24.</p>
                        </div>
                        <span class="badge neutral">Автоматически</span>
                    </div>
                    <div class="meta">
                        <div class="tile"><span class="muted">Портал</span><div style="margin-top:8px;font-weight:700;"><?= e((string)($portal['domain'] ?? '')) ?></div></div>
                        <div class="tile"><span class="muted">Установлено</span><div style="margin-top:8px;font-weight:700;"><?= e(UserInterface::formatDateTime((string)($portal['installed_at'] ?? ''))) ?></div></div>
                    </div>
                    <div class="readonly"><strong>URL обработчика исходящих SMS</strong><code><?= e($smsHandlerUrl) ?></code></div>
                    <div class="readonly"><strong>URL callback для статусов доставки</strong><code><?= e($statusCallbackUrl) ?></code></div>
                    <details><summary>Техническая информация</summary><pre><?= e(UserInterface::technicalJson(['member_id' => $memberId, 'app_url' => $appUrl, 'install_url' => $installUrl, 'sms_handler_url' => $smsHandlerUrl, 'status_callback_url' => $statusCallbackUrl])) ?></pre></details>
                </section>

                <section class="card">
                    <div class="header">
                        <div>
                            <h2>Проверка подключения</h2>
                            <p class="subtle">Проверка обращается к Notificore через endpoint баланса и подтверждает, что API-ключ и адрес API работают корректно.</p>
                        </div>
                        <span class="<?= e(badgeClass($connectionState['tone'])) ?>"><?= e($connectionState['label']) ?></span>
                    </div>
                    <div class="checklist">
                        <?php foreach ($connectionState['checklist'] as $item): ?>
                            <div class="check"><span><?= e((string)$item['label']) ?></span><span class="<?= e(badgeClass(($item['ready'] ?? false) ? 'ok' : 'warn')) ?>"><?= e(($item['ready'] ?? false) ? 'Готово' : 'Нужно заполнить') ?></span></div>
                        <?php endforeach; ?>
                    </div>
                    <div class="box" style="margin-top:16px;">
                        <h3><?= e($connectionState['summary']) ?></h3>
                        <p class="subtle"><?= e($connectionState['details']) ?></p>
                        <form method="post" class="actions" style="margin-top:14px;"><input type="hidden" name="form_type" value="connection_check"><button type="submit" class="secondary">Проверить подключение</button></form>
                    </div>
                    <?php if ($connectionResult !== null || $connectionError !== null): ?><div class="box" style="margin-top:12px;"><details><summary>Техническая информация</summary><pre><?= e(UserInterface::technicalJson($connectionResult ?? ['error_message' => $connectionError])) ?></pre></details></div><?php endif; ?>
                </section>

                <section class="card">
                    <div class="header">
                        <div>
                            <h2>Обновление статусов</h2>
                            <p class="subtle">Если callback задержался или был временно недоступен, статусы можно запросить вручную.</p>
                        </div>
                        <span class="badge neutral">Сервисная операция</span>
                    </div>
                    <form method="post">
                        <input type="hidden" name="form_type" value="sync_statuses">
                        <label for="sync_limit">Сколько сообщений проверить за один запуск</label>
                        <input id="sync_limit" type="number" min="1" max="100" name="sync_limit" value="20">
                        <div class="actions"><button type="submit" class="warm">Обновить статусы</button></div>
                    </form>
                    <?php if ($syncResult !== null): ?>
                        <div class="box" style="margin-top:16px;">
                            <div class="grid3">
                                <div class="tile"><span class="muted">Проверено</span><div style="margin-top:8px;font-weight:700;"><?= e((string)($syncResult['total'] ?? 0)) ?></div></div>
                                <div class="tile"><span class="muted">Обновлено</span><div style="margin-top:8px;font-weight:700;"><?= e((string)($syncResult['updated_count'] ?? 0)) ?></div></div>
                                <div class="tile"><span class="muted">Пропущено</span><div style="margin-top:8px;font-weight:700;"><?= e((string)($syncResult['skipped_count'] ?? 0)) ?></div></div>
                            </div>
                            <details><summary>Техническая информация</summary><pre><?= e(UserInterface::technicalJson($syncResult)) ?></pre></details>
                        </div>
                    <?php endif; ?>
                </section>
            </div>
        </div>

        <section class="card" style="margin-top:20px;">
            <div class="header">
                <div>
                    <h2>История сообщений</h2>
                    <p class="subtle">Показываются последние 50 записей по текущему порталу: тестовые отправки, сообщения из Bitrix24 и обновления статусов доставки.</p>
                </div>
                <span class="badge neutral"><?= e(count($messages)) ?> записей</span>
            </div>
            <?php if ($messages === []): ?>
                <p class="subtle">Пока нет ни одной записи. После первой отправки сообщение появится здесь автоматически.</p>
            <?php else: ?>
                <div class="history">
                    <table>
                        <thead><tr><th>Дата и время</th><th>Получатель</th><th>Сообщение</th><th>Статус</th><th>Детали</th></tr></thead>
                        <tbody>
                        <?php foreach ($messages as $message): ?>
                            <?php $statusMeta = UserInterface::statusMeta((string)($message['status'] ?? '')); ?>
                            <tr>
                                <td><div class="vstack"><strong><?= e(UserInterface::formatDateTime((string)($message['created_at'] ?? $message['ts'] ?? ''))) ?></strong><span class="muted"><?= e(UserInterface::sourceLabel((string)($message['source'] ?? ''))) ?></span><?php if (($message['is_test'] ?? false) === true): ?><span class="badge neutral">Тест</span><?php endif; ?></div></td>
                                <td><div class="vstack"><strong><?= e((string)($message['phone'] ?? '—')) ?></strong><?php if ((string)($message['bitrix_message_id'] ?? '') !== ''): ?><span class="muted">ID в Bitrix24: <?= e((string)$message['bitrix_message_id']) ?></span><?php endif; ?></div></td>
                                <td><div class="vstack"><strong><?= e(UserInterface::formatPreview((string)($message['message'] ?? ''))) ?></strong><?php if ((string)($message['error_message'] ?? '') !== ''): ?><span class="muted"><?= e(UserInterface::humanizeError((string)$message['error_message'])) ?></span><?php endif; ?></div></td>
                                <td><span class="<?= e(badgeClass((string)$statusMeta['tone'])) ?>"><?= e((string)$statusMeta['label']) ?></span><div class="muted" style="margin-top:8px;"><?= e((string)$statusMeta['description']) ?></div></td>
                                <td><details style="margin-top:0;padding-top:0;border-top:0;"><summary>Подробнее</summary><div class="vstack" style="margin-top:10px;"><span><strong>ID провайдера:</strong> <?= e((string)($message['provider_message_id'] ?? '—')) ?></span><span><strong>Reference:</strong> <?= e((string)($message['provider_reference'] ?? '—')) ?></span><span><strong>Обновлён:</strong> <?= e(UserInterface::formatDateTime((string)($message['status_updated_at'] ?? ''))) ?></span></div><pre><?= e(UserInterface::technicalJson(['send_result' => $message['send_result'] ?? [], 'status_payload' => $message['status_payload'] ?? []])) ?></pre></details></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</div>
<script>
if(window.BX24&&typeof BX24.init==='function'){BX24.init(function(){if(typeof BX24.resizeWindow==='function'){BX24.resizeWindow(document.body.scrollWidth,Math.min(document.body.scrollHeight,2000));}});}
</script>
</body>
</html>