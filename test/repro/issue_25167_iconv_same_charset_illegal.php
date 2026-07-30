<?php
/**
 * #25167 — iconv() same-charset illegal byte must notice + return false (php-src iconv.c).
 */
error_reporting(E_ALL);
$s = "a\x80b";
set_error_handler(static function (int $no, string $msg): bool {
    echo "WARN:$msg\n";

    return true;
});
$r = iconv('UTF-8', 'UTF-8', $s);
echo 'is_false=', var_export($r === false, true), "\n";
if (\is_string($r)) {
    echo 'hex=', bin2hex($r), "\n";
}
$ign = iconv('UTF-8', 'UTF-8//IGNORE', $s);
echo 'ignore_hex=', bin2hex((string) $ign), "\n";
