<?php
error_reporting(E_ALL);
$deps = 0;
set_error_handler(static function (int $n, string $m) use (&$deps): bool {
    if (E_DEPRECATED === $n) {
        ++$deps;
        echo "DEP\n";
    }

    return true;
});
$ch = curl_init('https://example.com');
var_export(is_array(curl_getinfo($ch, null)));
echo " deps=$deps\n";
var_export(curl_getinfo($ch, 0));
echo "\n";
