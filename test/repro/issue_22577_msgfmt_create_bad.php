<?php

/**
 * Repro for #22577 — MessageFormatter::create('{invalid') → null + U_UNMATCHED_BRACES.
 *
 * Requires host ext/intl for advertisement (or run via IntlModuleTest forced registration).
 */
if (!class_exists('MessageFormatter')) {
    fwrite(STDERR, "skip: MessageFormatter not advertised (need extension_loaded('intl'))\n");
    exit(0);
}

echo 'idle_msg=', var_export(intl_get_error_message(), true), "\n";
echo 'idle_code=', intl_get_error_code(), "\n";
$bad = MessageFormatter::create('en_US', '{invalid');
echo 'type=', get_debug_type($bad), "\n";
echo 'msg=', var_export(intl_get_error_message(), true), "\n";
echo 'code=', intl_get_error_code(), "\n";
try {
    $x = new MessageFormatter('en_US', '{invalid');
    echo 'construct=', get_debug_type($x), "\n";
} catch (Throwable $e) {
    echo 'construct_err=', get_class($e), ':', $e->getMessage(), "\n";
}
