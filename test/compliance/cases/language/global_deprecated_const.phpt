--TEST--
Language: #[\Deprecated] on global constants emits E_USER_DEPRECATED on fetch (#16819, #26308, Zend/zend_attributes.stub.php)
--ENV--
PHP_COMPILER_PROFILE=8.5
--SKIPIF--
<?php
if (!PHPCompiler\CompilerVersion::supportsGlobalDeprecatedConstAttributes()) {
    die('skip global deprecated constants require PHP_COMPILER_PROFILE=8.5');
}
?>
--FILE--
<?php
ini_set('display_errors', '0');
ini_set('error_reporting', '32767');

#[\Deprecated(since: '8.4')]
const FOO = 42;

echo FOO, "\n";
$last = error_get_last();
echo ($last['message'] ?? ''), "\n";
echo ($last['type'] ?? 0) === 16384 ? "dep\n" : "no\n";
--EXPECT--
42
Constant FOO is deprecated since 8.4
dep
