<?php
// #31514 — SimpleXMLElement(null): soft-null E_DEPRECATED then Exception (php-src sxe.c).
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
    $x = new SimpleXMLElement(null);
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
