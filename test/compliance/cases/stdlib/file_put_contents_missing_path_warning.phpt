--TEST--
stdlib file_put_contents() missing path — E_WARNING + false (#11034, ext/standard/file.c)
--FILE--
<?php
$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
});
$r = file_put_contents('/no/such/phpc-fpc-missing-dir/out.txt', 'data');
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
