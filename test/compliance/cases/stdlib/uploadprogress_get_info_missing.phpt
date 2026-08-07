--TEST--
stdlib uploadprogress_get_info() — missing identifier returns null (#6386, ext/uploadprogress)
--ENV--
PHP_COMPILER_ENABLE_UPLOADPROGRESS=1
--SKIPIF--
<?php
if (!\PHPCompiler\ext\uploadprogress\UploadprogressExtensionPolicy::advertisesExtension()) {
    die('skip uploadprogress withheld (#26744)');
}
?>
--FILE--
<?php
declare(strict_types=1);

echo (int) function_exists('uploadprogress_get_info'), "\n";
echo (int) function_exists('uploadprogress_get_contents'), "\n";
echo (int) extension_loaded('uploadprogress'), "\n";
var_export(uploadprogress_get_info('missing-id'));
echo "\n";
--EXPECT--
1
1
1
NULL
