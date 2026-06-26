--TEST--
stdlib header() after output warns and returns null (#12151, ext/standard/head.c)
--FILE--
<?php
$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
});
echo 'x';
$r = header('Y: z');
var_export($r);
echo "\n";
echo 'warnings=', count($warnings), "\n";
if ($warnings) {
    echo str_contains($warnings[0], 'headers already sent by') ? 'warn_ok' : 'warn_bad', "\n";
}
--EXPECT--
xNULL
warnings=1
warn_ok
