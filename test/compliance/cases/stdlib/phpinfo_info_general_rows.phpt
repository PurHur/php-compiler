--TEST--
stdlib phpinfo(INFO_GENERAL) includes ini path, stream rows, and API rows (#14283, #16551, ext/standard/info.c)
--FILE--
<?php
ob_start();
phpinfo(INFO_GENERAL);
$out = ob_get_clean();
echo str_contains($out, 'Configuration File (php.ini) Path') ? "ini-path\n" : "ini-path-missing\n";
echo str_contains($out, 'Loaded Configuration File') ? "loaded-ini\n" : "loaded-ini-missing\n";
echo str_contains($out, 'PHP API') ? "php-api\n" : "php-api-missing\n";
echo str_contains($out, 'Zend Extension') ? "zend-ext\n" : "zend-ext-missing\n";
echo str_contains($out, 'Registered PHP Streams') ? "streams\n" : "streams-missing\n";
echo str_contains($out, 'Zend Signal Handling') ? "signal\n" : "signal-missing\n";
echo strlen($out) >= 1800 ? "size-ok\n" : "size-bad\n";
?>
--EXPECT--
ini-path
loaded-ini
php-api
zend-ext
streams
signal
size-ok
