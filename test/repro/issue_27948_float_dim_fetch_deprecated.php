<?php
/**
 * #27948 — finite float array dim read/isset/unset emit E_DEPRECATED like Zend.
 */
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $msg): bool {
    if ($no === E_DEPRECATED) {
        echo 'DEPRECATED:', $msg, "\n";

        return true;
    }

    return false;
});
$a = [10, 20, 30];
echo 'read:', $a[1.5], "\n";
echo 'isset:', isset($a[1.5]) ? '1' : '0', "\n";
unset($a[1.5]);
echo 'unset_count:', count($a), "\n";
$b = [];
$b[1.5] = 'x';
echo 'write:', $b[1], "\n";
$c = ['k' => 'v', 2 => 'two'];
echo 'strmix:', $c[2.9], "\n";
