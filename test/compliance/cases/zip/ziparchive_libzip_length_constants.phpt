--TEST--
ZipArchive LENGTH_UNCHECKED / ER_DATA_LENGTH / ER_TRUNCATED_ZIP / FL_OPEN_FILE_NOW / LIBZIP_VERSION (#20712)
--ENV--
PHP_COMPILER_ENABLE_ZIP=1
PHP_COMPILER_PROFILE=8.4
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\ext\zip\ZipExtensionPolicy::advertisesExtension()) {
    die('skip zip withheld (#18137/#25010)');
}
?>
--FILE--
<?php
echo 'LENGTH_TO_END=', (int) ZipArchive::LENGTH_TO_END, "\n";
echo 'LENGTH_UNCHECKED=', (int) ZipArchive::LENGTH_UNCHECKED, "\n";
echo 'ER_DATA_LENGTH=', (int) ZipArchive::ER_DATA_LENGTH, "\n";
echo 'ER_TRUNCATED_ZIP=', (int) ZipArchive::ER_TRUNCATED_ZIP, "\n";
echo 'FL_OPEN_FILE_NOW=', (int) ZipArchive::FL_OPEN_FILE_NOW, "\n";
echo 'LIBZIP_VERSION=', ZipArchive::LIBZIP_VERSION, "\n";
echo 'defined_unchecked=', defined('ZipArchive::LENGTH_UNCHECKED') ? 'yes' : 'no', "\n";
echo 'defined_fl=', defined('ZipArchive::FL_OPEN_FILE_NOW') ? 'yes' : 'no', "\n";
echo 'defined_libzip=', defined('ZipArchive::LIBZIP_VERSION') ? 'yes' : 'no', "\n";
?>
--EXPECT--
LENGTH_TO_END=0
LENGTH_UNCHECKED=-2
ER_DATA_LENGTH=33
ER_TRUNCATED_ZIP=35
FL_OPEN_FILE_NOW=1073741824
LIBZIP_VERSION=1.11.3
defined_unchecked=yes
defined_fl=yes
defined_libzip=yes
