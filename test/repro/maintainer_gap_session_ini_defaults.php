<?php
declare(strict_types=1);

$useCookies = ini_get('session.use_cookies');
$useOnlyCookies = ini_get('session.use_only_cookies');
$saveHandler = ini_get('session.save_handler');

if ('1' !== $useCookies || '1' !== $useOnlyCookies || 'files' !== $saveHandler) {
    echo 'fail: use_cookies=', var_export($useCookies, true);
    echo ' use_only_cookies=', var_export($useOnlyCookies, true);
    echo ' save_handler=', var_export($saveHandler, true), "\n";
    exit(1);
}
echo "ok\n";
