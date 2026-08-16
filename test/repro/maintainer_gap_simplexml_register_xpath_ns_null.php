<?php
// SimpleXMLElement::registerXPathNamespace(null,…): soft-null DEP + empty-prefix false (php-src sxe.c).
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo "DEP:{$msg}\n";
        return true;
    }
    echo "E{$no}:{$msg}\n";
    return true;
});
try {
    $x = new SimpleXMLElement('<a/>');
    $a = $x->registerXPathNamespace(null, 'urn:x');
    echo 'null_prefix=', var_export($a, true), "\n";
    $b = $x->registerXPathNamespace('p', null);
    echo 'null_ns=', var_export($b, true), "\n";
    $c = $x->registerXPathNamespace('', 'urn:y');
    echo 'empty_prefix=', var_export($c, true), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
