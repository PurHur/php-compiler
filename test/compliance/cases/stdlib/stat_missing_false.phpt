--TEST--
stdlib stat()/lstat() missing path — false not NULL, even under @ (#10336, ext/standard/stat.c)
--FILE--
<?php
$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
});
$r = stat('/no/such/phpc-maintainer-stat-warn');
var_export($r);
echo "\n";
echo 'warnings=', count($warnings), "\n";
if ($warnings) {
    echo str_contains($warnings[0], 'stat failed') ? 'warn_ok' : 'warn_bad', "\n";
}
var_export(@stat('/no/such/phpc-maintainer-stat'));
echo "\n";
var_export(@lstat('/no/such/phpc-maintainer-stat'));
echo "\n";
--EXPECT--
false
warnings=1
warn_ok
false
false
