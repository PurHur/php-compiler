--TEST--
stdlib md5_file() missing path — E_WARNING + false (#10625, ext/standard/md5.c)
--FILE--
<?php
$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
});
$r = md5_file('/no/such/phpc-md5-file-missing-path');
var_export($r);
echo "\n";
echo 'warnings=', count($warnings), "\n";
if ($warnings) {
    echo str_contains($warnings[0], 'Failed to open stream') ? 'warn_ok' : 'warn_bad', "\n";
}
--EXPECT--
false
warnings=1
warn_ok
