--TEST--
AOT: locale_get_primary_language/region/script BCP-47 parsers (#17072, ext/intl/locale_methods.c)
--SKIPIF--
<?php
if (!class_exists('PHPCompiler\\CompilerVersion')) {
    require __DIR__ . '/../../../../vendor/autoload.php';
}
if (!PHPCompiler\CompilerVersion::supportsLocaleParserForwardProfile()) {
    die('skip locale_get_* parsers require PHP_COMPILER_PROFILE=8.4');
}
?>
--FILE--
<?php
declare(strict_types=1);

echo locale_get_primary_language('en_US_POSIX'), "\n";
echo locale_get_region('en_US_POSIX'), "\n";
echo locale_get_script('zh-Hans-CN'), "\n";
--EXPECT--
en
US
Hans
