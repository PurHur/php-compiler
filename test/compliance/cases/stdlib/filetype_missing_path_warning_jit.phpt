--TEST--
JIT: filetype() missing path — E_WARNING + false (#10548, ext/standard/filestat.c)
--FILE--
<?php
$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
});
$r = filetype('/no/such/phpc-filetype-missing-path');
var_export($r);
echo "\n";
echo 'warnings=', count($warnings), "\n";
if ($warnings) {
    echo str_contains($warnings[0], 'Lstat failed') ? 'warn_ok' : 'warn_bad', "\n";
}
--EXPECT--
false
warnings=1
warn_ok
