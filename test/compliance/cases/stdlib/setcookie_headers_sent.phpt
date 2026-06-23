--TEST--
stdlib setcookie() after output warns and returns false (#10865, ext/standard/head.c)
--FILE--
<?php
$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
});
echo 'x';
$r = setcookie('a', 'b');
var_export($r);
echo "\n";
echo 'warnings=', count($warnings), "\n";
if ($warnings) {
    echo str_contains($warnings[0], 'Cannot modify header information') ? 'warn_ok' : 'warn_bad', "\n";
}
--EXPECT--
xfalse
warnings=1
warn_ok
