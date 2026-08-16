<?php
// SimpleXMLElement::xpath(null): soft-null DEP + warning + false (php-src sxe.c).
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
    $x = new SimpleXMLElement('<a><b/></a>');
    $r = $x->xpath(null);
    echo var_export($r, true), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
