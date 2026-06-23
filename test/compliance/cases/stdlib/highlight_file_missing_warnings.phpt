--TEST--
stdlib highlight_file() missing path emits two E_WARNINGs (#10875, ext/standard/url.c)
--FILE--
<?php
$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;

    return true;
});
$r = highlight_file('/no/such/phpc-highlight-missing.php', true);
var_export($r);
echo "\n";
echo 'warnings=', count($warnings), "\n";
if ($warnings) {
    echo str_contains($warnings[0], 'Failed to open stream') ? 'warn1_ok' : 'warn1_bad', "\n";
    echo str_contains($warnings[1], 'Failed opening') ? 'warn2_ok' : 'warn2_bad', "\n";
}
--EXPECT--
false
warnings=2
warn1_ok
warn2_ok
