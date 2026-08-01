<?php
/**
 * Repro #26265 — legacy DOMElement allows dynamic props with E_DEPRECATED (Zend php-src-strict).
 * Dom\ living nodes match the same Deprecated+write path (#26566; re-#26055).
 */
ini_set('error_reporting', (string) E_ALL);
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo 'DEPRECATED:', $msg, "\n";

        return true;
    }

    return false;
});

$d = new DOMDocument();
$d->loadXML('<a/>');
$el = $d->documentElement;
try {
    $el->foo = 1;
    echo 'WRITE_OK isset=', isset($el->foo) ? '1' : '0', ' val=', $el->foo, "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
