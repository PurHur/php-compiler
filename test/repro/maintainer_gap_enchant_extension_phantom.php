<?php
declare(strict_types=1);
/**
 * #23963 — ext/enchant must not phantom when host Zend lacks ext/enchant.
 */
if (extension_loaded('enchant')) {
    fwrite(STDERR, "skip host ext/enchant loaded\n");
    exit(0);
}
$loaded = extension_loaded('enchant');
$init = function_exists('enchant_broker_init');
if ($loaded || $init) {
    fwrite(STDERR, "FAIL: extension_loaded(enchant)=" . var_export($loaded, true)
        . " function_exists(enchant_broker_init)=" . var_export($init, true) . "\n");
    exit(1);
}
echo "ok\n";
