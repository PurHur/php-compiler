--TEST--
stdlib uploadprogress_get_contents() — disabled by default (#6386, ext/uploadprogress)
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

$result = @uploadprogress_get_contents('missing-id', 'file');
echo ($result === false ? "disabled\n" : "unexpected\n");
--EXPECT--
disabled
