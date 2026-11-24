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
  <link rel="stylesheet" href="<?= $path('/assets/workspace-auth.css') ?>">
  <script defer src="<?= $path('/assets/workspace-paths.js') ?>"></script>
  <script defer src="<?= $path('/assets/vitrine-notifications.js') ?>"></script>
  <script defer src="<?= $path('/assets/workspace-auth.js') ?>"></script>
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
        <label class="field">
          <span class="field-label">Пароль</span>
          <input class="field-input" name="password" type="password" autocomplete="current-password" required maxlength="256">
        </label>
      </div>
      <footer class="modal-foot">
        <button class="btn btn-primary" type="submit">Войти</button>
      </footer>
    </form>
  </main>
</body>
</html>
