@echo off
cd /d "%~dp0..\.."
echo http://127.0.0.1:33595/
php -S 127.0.0.1:33595 examples/field-types/index.php
