--TEST--
stdlib php_strip_whitespace() missing path — E_WARNING + '' (#12094, ext/standard/basic_functions.c)
--FILE--
<?php
$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
});
$r = php_strip_whitespace('/no/such/phpc-php-strip-whitespace-missing.php');
var_export($r);
echo "\n";
echo 'warnings=', count($warnings), "\n";
if ($warnings) {
    echo str_contains($warnings[0], 'Failed to open stream') ? 'warn_ok' : 'warn_bad', "\n";
}
--EXPECT--
''
warnings=1
warn_ok
