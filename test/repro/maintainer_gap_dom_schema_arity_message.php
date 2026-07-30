<?php

declare(strict_types=1);

/**
 * Repro #25323 — DOMDocument schema/relaxNG validate ArgumentCountError wording
 * (php-src ext/dom/document.c / php_dom.stub.php).
 *
 * schemaValidate / schemaValidateSource: optional $flags → "at least"
 * relaxNGValidate / relaxNGValidateSource: fixed arity → "exactly"
 */

$doc = new DOMDocument();

$methods = [
    'schemaValidate',
    'relaxNGValidate',
    'schemaValidateSource',
    'relaxNGValidateSource',
];

foreach ($methods as $method) {
    try {
        $doc->$method();
        echo $method, ": no error\n";
    } catch (ArgumentCountError $e) {
        echo $method, ': ', $e->getMessage(), "\n";
    }
}

// 1-arg must not raise ArgumentCountError (may warn / return false on missing file).
set_error_handler(static function (): bool {
    return true;
});
foreach (['schemaValidate', 'relaxNGValidate'] as $method) {
    try {
        $doc->$method('/nonexistent-schema-25323');
        echo $method, "_1arg: ok\n";
    } catch (ArgumentCountError $e) {
        echo $method, '_1arg: ', $e->getMessage(), "\n";
    }
}
foreach (['schemaValidateSource', 'relaxNGValidateSource'] as $method) {
    try {
        $doc->$method('<x/>');
        echo $method, "_1arg: ok\n";
    } catch (ArgumentCountError $e) {
        echo $method, '_1arg: ', $e->getMessage(), "\n";
    }
}
restore_error_handler();
