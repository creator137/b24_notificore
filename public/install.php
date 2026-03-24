<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use App\Application\InstallAppAction;
use App\Application\RegisterSenderAction;
use App\Support\BitrixRequest;
use App\Support\HttpRequest;
use App\Support\UserInterface;

function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$container = app_container();
$action = new InstallAppAction(
    portalRepository: $container->portalRepository(),
    registerSenderAction: new RegisterSenderAction(),
);

$payload = HttpRequest::payload();
$installPortal = BitrixRequest::extractPortal($payload);
$isDevMode = filter_var($_ENV['DEV_MODE'] ?? false, FILTER_VALIDATE_BOOL);
$hasInstallPayload = (string)($installPortal['member_id'] ?? '') !== ''
    && (string)($installPortal['domain'] ?? '') !== ''
    && (string)($installPortal['access_token'] ?? '') !== '';
$manualInstallRequested = HttpRequest::method() === 'POST' && (string)($payload['form_type'] ?? '') === 'dev_install';
$result = null;
$error = null;

if ($hasInstallPayload || $manualInstallRequested) {
    try {
        $result = $action($payload);
    } catch (Throwable $e) {
        $error = UserInterface::humanizeError($e->getMessage());
    }
}

$appUrl = $container->config()->handlerUrl('app.php');
$installUrl = $container->config()->handlerUrl('install.php');
$smsHandlerUrl = $container->config()->handlerUrl('sms_handler.php');
$statusCallbackUrl = $container->config()->handlerUrl('status_callback.php');
$portalMemberId = (string)($result['portal']['member_id'] ?? '');
$openAppUrl = $portalMemberId !== '' ? $appUrl . '?member_id=' . rawurlencode($portalMemberId) : $appUrl;
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Установка Notificore для Bitrix24</title>
    <style>
        :root{--bg:#f5efe4;--panel:#fffdf8;--ink:#142230;--muted:#637083;--line:#decfb8;--accent:#0f766e;--accent-soft:#daf5ef;--warn:#a66300;--warn-soft:#fff0c8;--danger:#b42318;--danger-soft:#fee4e2;--shadow:0 24px 56px rgba(83,62,26,.14)}
        *{box-sizing:border-box}body{margin:0;font-family:"Trebuchet MS","Segoe UI",sans-serif;color:var(--ink);background:radial-gradient(circle at top right,rgba(15,118,110,.16),transparent 28%),linear-gradient(180deg,#fbf7f0 0%,var(--bg) 100%)}
        .page{max-width:1080px;margin:0 auto;padding:30px 18px 42px}.hero,.grid,.meta{display:grid;gap:18px}.hero{grid-template-columns:minmax(0,1.35fr) minmax(260px,.85fr);margin-bottom:20px}.grid{grid-template-columns:repeat(auto-fit,minmax(300px,1fr))}.meta{grid-template-columns:repeat(2,minmax(0,1fr))}
        .card,.hero-main,.hero-side{background:var(--panel);border:1px solid var(--line);border-radius:24px;box-shadow:var(--shadow);padding:22px}.hero-main{background:linear-gradient(135deg,rgba(15,118,110,.08),rgba(255,248,238,.92)),var(--panel)}
        .eyebrow,.badge{display:inline-flex;align-items:center;justify-content:center;padding:6px 12px;border-radius:999px;font-size:12px;font-weight:700}.eyebrow{background:rgba(15,118,110,.12);color:#0d5d58;text-transform:uppercase;letter-spacing:.04em}.badge.ok{background:#dcfae6;color:#067647}.badge.warn{background:#fff0c8;color:#9a6700}.badge.neutral{background:#eef2f6;color:#475467}
        h1{margin:16px 0 12px;font-size:36px;line-height:1.03}h2{margin:0 0 10px;font-size:24px}h3{margin:0 0 8px;font-size:18px}p{margin:0;line-height:1.58}.subtle,.hint{color:var(--muted)}.hint{font-size:13px}.header{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:16px}
        .alert,.tile{border:1px solid var(--line);border-radius:18px;padding:14px}.alert{margin-bottom:18px}.alert strong{display:block;margin-bottom:4px}.alert.ok{background:var(--accent-soft);border-color:rgba(15,118,110,.2)}.alert.error{background:var(--danger-soft);border-color:rgba(180,35,24,.2);color:var(--danger)}
        label{display:block;margin-top:14px;font-weight:700}input{width:100%;margin-top:6px;padding:12px 13px;border:1px solid var(--line);border-radius:14px;background:#fff;font:inherit}button,.linkbtn{display:inline-flex;align-items:center;justify-content:center;min-height:46px;padding:12px 16px;border-radius:14px;border:0;background:var(--accent);color:#fff;font:inherit;font-weight:700;text-decoration:none;cursor:pointer}.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}
        code,pre{background:#f6efe3;border-radius:14px}code{display:block;margin-top:8px;padding:10px 12px;white-space:pre-wrap;word-break:break-word}pre{margin:10px 0 0;padding:14px;overflow:auto;white-space:pre-wrap;word-break:break-word}details{margin-top:16px;padding-top:14px;border-top:1px solid #ebe1d2}details summary{cursor:pointer;font-weight:700;list-style:none}details summary::-webkit-details-marker{display:none}
        @media (max-width:900px){.hero{grid-template-columns:1fr}}@media (max-width:720px){.page{padding:18px 14px 34px}h1{font-size:30px}.meta{grid-template-columns:1fr}}
    </style>
    <script src="https://api.bitrix24.com/api/v1/"></script>
</head>
<body>
<div class="page">
    <section class="hero">
        <div class="hero-main">
            <span class="eyebrow">Bitrix24 + Notificore</span>
            <h1>Установка приложения</h1>
            <p class="subtle">Эта страница сохраняет портал Bitrix24, регистрирует SMS-обработчик и подготавливает приложение к дальнейшей настройке в пользовательском интерфейсе.</p>
        </div>
        <aside class="hero-side">
            <h3>Что происходит дальше</h3>
            <p class="subtle">После успешной установки откройте приложение, сохраните настройки Notificore и выполните тестовую отправку.</p>
        </aside>
    </section>

    <?php if ($result !== null): ?>
        <div class="alert ok">
            <strong>Установка завершена</strong>
            <div>Портал привязан, обработчик подготовлен, приложение можно открывать для дальнейшей настройки.</div>
            <div class="actions"><a class="linkbtn" href="<?= e($openAppUrl) ?>">Открыть приложение</a></div>
        </div>
    <?php endif; ?>

    <?php if ($error !== null): ?>
        <div class="alert error"><strong>Не удалось завершить установку</strong><div><?= e($error) ?></div></div>
    <?php endif; ?>

    <div class="grid">
        <section class="card">
            <div class="header">
                <div>
                    <h2>Автоматическая установка</h2>
                    <p class="subtle">Это основной сценарий. Bitrix24 сам открывает этот URL во время установки приложения и передаёт данные портала.</p>
                </div>
                <span class="badge <?= e($result !== null ? 'ok' : 'neutral') ?>"><?= e($result !== null ? 'Готово' : 'Ожидание') ?></span>
            </div>
            <div class="meta">
                <div class="tile"><span class="hint">Установочный URL</span><code><?= e($installUrl) ?></code></div>
                <div class="tile"><span class="hint">URL приложения</span><code><?= e($appUrl) ?></code></div>
                <div class="tile"><span class="hint">Обработчик SMS</span><code><?= e($smsHandlerUrl) ?></code></div>
                <div class="tile"><span class="hint">Callback статусов</span><code><?= e($statusCallbackUrl) ?></code></div>
            </div>
        </section>

        <section class="card">
            <div class="header">
                <div>
                    <h2>Дальнейшие шаги</h2>
                    <p class="subtle">После установки приложение уже должно открываться как обычная страница Bitrix24.</p>
                </div>
                <span class="badge warn">Далее</span>
            </div>
            <div class="tile"><strong>1.</strong> Откройте приложение внутри Bitrix24 или по ссылке <code><?= e($appUrl) ?></code>.</div>
            <div class="tile" style="margin-top:12px;"><strong>2.</strong> Укажите API-ключ Notificore и имя отправителя.</div>
            <div class="tile" style="margin-top:12px;"><strong>3.</strong> Проверьте подключение и выполните тестовую отправку.</div>
        </section>
    </div>

    <?php if ($isDevMode): ?>
        <section class="card" style="margin-top:20px;">
            <h2>Режим разработки</h2>
            <p class="subtle">Служебная форма для локальных и тестовых сценариев. Обычному пользователю Bitrix24 она не нужна.</p>
            <details>
                <summary>Открыть служебную форму</summary>
                <form method="post">
                    <input type="hidden" name="form_type" value="dev_install">
                    <label for="DOMAIN">DOMAIN</label><input id="DOMAIN" type="text" name="DOMAIN" value="local.test">
                    <label for="AUTH_ID">AUTH_ID</label><input id="AUTH_ID" type="text" name="AUTH_ID" value="mock-token">
                    <label for="REFRESH_ID">REFRESH_ID</label><input id="REFRESH_ID" type="text" name="REFRESH_ID" value="mock-refresh-token">
                    <label for="AUTH_EXPIRES">AUTH_EXPIRES</label><input id="AUTH_EXPIRES" type="text" name="AUTH_EXPIRES" value="3600">
                    <label for="member_id">member_id</label><input id="member_id" type="text" name="member_id" value="dev-installed-portal">
                    <div class="actions"><button type="submit">Смоделировать установку</button></div>
                </form>
            </details>
        </section>
    <?php endif; ?>

    <?php if ($result !== null): ?>
        <section class="card" style="margin-top:20px;">
            <h2>Подробности установки</h2>
            <details open>
                <summary>Техническая информация</summary>
                <pre><?= e(UserInterface::technicalJson($result)) ?></pre>
            </details>
        </section>
    <?php endif; ?>
</div>
<?php if ($result !== null): ?>
<script>
if(window.BX24&&typeof BX24.installFinish==='function'){BX24.init(function(){BX24.installFinish();if(typeof BX24.resizeWindow==='function'){BX24.resizeWindow(document.body.scrollWidth,document.body.scrollHeight);}});}
</script>
<?php endif; ?>
</body>
</html>