-- Run on the application's database after deploying the login-only account code.
DELETE FROM workspace_meta WHERE `key` REGEXP '^employee_email_[0-9]+$';
