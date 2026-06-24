--TEST--
stdlib mkdir() recursive on existing directory JIT (issue #11186)
--FILE--
<?php
$dir = 'test/compliance/cases/stdlib/mkdir_recursive_exists_fixture/'.getmypid();
@mkdir($dir, 0777, true);
$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;
    return true;
});
$result = mkdir($dir, 0777, true);
echo 'warnings=', count($warnings), ' result=', var_export($result, true), "\n";
if (1 === count($warnings)) {
    echo $warnings[0], "\n";
}
@rmdir($dir);
--EXPECT--
warnings=1 result=false
mkdir(): File exists
