<button class="auth-password-toggle<?= !empty($passwordEyeInline) ? ' vitrine-cell-icon' : '' ?>" type="button" aria-label="Показать пароль" aria-controls="<?= htmlspecialchars($passwordEyeField,ENT_QUOTES,'UTF-8') ?>" hidden>
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    <path d="M2 12s3.6-6 10-6 10 6 10 6-3.6 6-10 6S2 12 2 12Z"/>
    <circle cx="12" cy="12" r="2.75"/>
    <path class="auth-password-slash" d="m4 4 16 16"/>
  </svg>
</button>
