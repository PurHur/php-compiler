--TEST--
stdlib rmdir() — non-empty directory E_WARNING + false (#10931)
--FILE--
<?php
$d = sys_get_temp_dir().'/phpc_rmdir_nonempty_compliance_'.getmypid();
mkdir($d);
file_put_contents($d.'/file', 'x');
$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;
    return true;
});
$result = rmdir($d);
echo 'warnings=', count($warnings), ' result=', var_export($result, true), "\n";
@unlink($d.'/file');
@rmdir($d);
--EXPECT--
warnings=1 result=false
