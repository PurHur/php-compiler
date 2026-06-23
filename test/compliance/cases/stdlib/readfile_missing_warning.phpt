--TEST--
stdlib readfile() — missing path E_WARNING + false (#10932)
--FILE--
<?php
$path = '/no/such/phpc-readfile-missing-compliance';
$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;
    return true;
});
$result = readfile($path);
echo 'warnings=', count($warnings), ' result=', var_export($result, true), "\n";
--EXPECT--
warnings=1 result=false
