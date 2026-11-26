<?php
declare(strict_types=1);

$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$path = static fn(string $value): string => $escape(app_url_path($value));
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="app-base-path" content="<?= $escape(runtime_config()['APP_BASE_PATH']) ?>">
  <meta name="csrf-token" content="<?= $escape(csrf_token()) ?>">
  <title>Вход — ЛК ДЛТК</title>
  <link rel="stylesheet" href="<?= $path('/assets/vitrine-base.css') ?>">
  <link rel="stylesheet" href="<?= $path('/assets/vitrine.css') ?>">
  <link rel="stylesheet" href="<?= $path('/assets/workspace-auth.css?v=6') ?>">
  <link rel="stylesheet" href="<?= $path('/assets/workspace-login-transition.css') ?>">
  <script defer src="<?= $path('/assets/workspace-paths.js') ?>"></script>
  <script defer src="<?= $path('/assets/vitrine-notifications.js') ?>"></script>
  <script defer src="<?= $path('/assets/workspace-auth.js?v=4') ?>"></script>
  <script src="<?= $path('/assets/vitrine-theme.js') ?>"></script>
</head>
<body class="vitrine auth-page">
  <header class="vitrine-topbar">
    <div class="sidebar-brand vitrine-brand">
      <span class="logo-mark" aria-hidden="true"></span>
      <span class="brand-name">ЛК ДЛТК</span>
    </div>
  </header>
  <main class="auth-layout">
    <form id="workspace-login" class="modal modal-sm auth-card" method="post" action="<?= $path('/login') ?>" aria-labelledby="login-title">
      <header class="modal-head">
        <h1 id="login-title" class="modal-title">Вход в систему</h1>
      </header>
      <div class="modal-body auth-fields">
        <label class="field">
          <span class="field-label">Логин</span>
          <input class="field-input" name="login" autocomplete="username" autofocus required maxlength="80">
        </label>
        <div class="field">
          <label class="field-label" for="workspace-password">Пароль</label>
          <div class="auth-password">
            <input class="field-input" id="workspace-password" name="password" type="password" autocomplete="current-password" required maxlength="256">
            <button class="auth-password-toggle" type="button" aria-label="Показать пароль" aria-controls="workspace-password" hidden>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M2 12s3.6-6 10-6 10 6 10 6-3.6 6-10 6S2 12 2 12Z"/>
                <circle cx="12" cy="12" r="2.75"/>
                <path class="auth-password-slash" d="m4 4 16 16"/>
              </svg>
            </button>
          </div>
        </div>
      </div>
      <footer class="modal-foot">
        <button class="btn btn-primary auth-submit" type="submit">
          Войти
          <span class="auth-submit-spinner" aria-hidden="true" hidden></span>
        </button>
      </footer>
    </form>
  </main>
</body>
</html>
