--TEST--
stdlib mime_content_type() missing path — E_WARNING + false (#12096, ext/standard/file.c)
--FILE--
<?php
$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
});
$r = mime_content_type('/no/such/phpc-mime-content-type-missing.bin');
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
