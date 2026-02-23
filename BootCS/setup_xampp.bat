@echo off
echo Instalando dependencias del chatbot...
cd /d "C:\xampp\htdocs\chatbot-pedidos"
set PATH=%PATH%;C:\xampp\php

echo 1. Instalando Composer...
curl -sS https://getcomposer.org/installer -o composer-setup.php
php composer-setup.php --quiet
del composer-setup.php
if exist composer.phar move composer.phar composer.exe

echo 2. Instalando Google API...
composer require google/apiclient:^2.12 --quiet

echo 3. Verificando instalacion...
if exist "vendor\autoload.php" (
    echo ✅ Instalación completada!
    echo.
    echo URL para probar:
    echo http://localhost/chatbot-pedidos/test_connection.php
    pause
) else (
    echo ❌ Error en la instalación
    pause
)