<?php
/**
 * #27926 — INF/NAN→int untyped coerce emits E_DEPRECATED like Zend.
 */
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $msg): bool {
    if ($no === E_DEPRECATED) {
        echo 'DEPRECATED:', $msg, "\n";

        return true;
    }

    return false;
});
echo 'bit:', (INF | 1), "\n";
$a = [1, 2, 3];
echo 'dim:', $a[INF] ?? 'miss', "\n";
echo 'neg:', ((-INF) | 1), "\n";
echo 'nan:', (NAN | 1), "\n";
echo 'finite:', (1.5 | 2), "\n";
