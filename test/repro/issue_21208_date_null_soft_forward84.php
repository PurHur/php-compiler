<?php
/**
 * #21208 — date()/gmdate()/strtotime(null) soft-null on PROFILE=8.4
 * (php-src ext/date/php_date.c; reverts over-strict #19651 TypeError).
 */
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo "DEP\n";
        return true;
    }
    return false;
});
foreach ([['date', [null]], ['gmdate', [null]], ['strtotime', [null]]] as [$f, $a]) {
    try {
        $r = $f(...$a);
        echo $f, ' OK ', var_export($r, true), PHP_EOL;
    } catch (Throwable $e) {
        echo $f, ' ', get_class($e), PHP_EOL;
    }
}
