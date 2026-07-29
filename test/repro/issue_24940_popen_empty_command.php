<?php
/**
 * #24940 — popen('') / popen(null) return stream (Zend); not ValueError (re-#24688 inverted).
 */
error_reporting(E_ALL);
set_error_handler(static function (int $n, string $m): bool {
    if ($n === E_DEPRECATED) {
        echo 'DEP: ', $m, "\n";
    }

    return true;
});

$h = popen('', 'r');
echo 'empty=', (int) (false !== $h && (is_resource($h) || is_object($h))), "\n";
if (false !== $h) {
    echo 'pclose_empty=', (int) (is_int(pclose($h))), "\n";
}

$h = popen(null, 'r');
echo 'null=', (int) (false !== $h && (is_resource($h) || is_object($h))), "\n";
if (false !== $h) {
    echo 'pclose_null=', (int) (is_int(pclose($h))), "\n";
}
