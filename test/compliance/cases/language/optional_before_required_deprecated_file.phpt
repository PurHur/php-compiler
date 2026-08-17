--TEST--
language: file-level optional-before-required E_DEPRECATED (zend_compile.c, #31904)
--INI--
error_reporting=E_ALL
--FILE--
<?php
function issue31904_file_fn($a = 1, $b) {}
class Issue31904_FileClass {
    public function method($a = 1, $b) {}
}
$c = function ($a = 1, $b) { return 1; };
$a = fn($a = 1, $b) => 1;
try {
    issue31904_file_fn(b: 2);
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
echo "ok\n";
--EXPECTF--
PHP Deprecated:  Optional parameter $a declared before required parameter $b is implicitly treated as a required parameter in %s on line %d
PHP Deprecated:  Optional parameter $a declared before required parameter $b is implicitly treated as a required parameter in %s on line %d
PHP Deprecated:  Optional parameter $a declared before required parameter $b is implicitly treated as a required parameter in %s on line %d
PHP Deprecated:  Optional parameter $a declared before required parameter $b is implicitly treated as a required parameter in %s on line %d
issue31904_file_fn(): Argument #1 ($a) not passed
ok
