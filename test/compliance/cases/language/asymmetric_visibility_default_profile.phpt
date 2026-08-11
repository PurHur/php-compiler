--TEST--
Language: public private(set) accepted on default 8.4.0-dev profile (#30205, re-#24819, Zend/zend_compile.c)
--SKIPIF--
<?php
// Key off PROFILE env — explicit non-8.4 profile would reject this syntax.
$raw = getenv('PHP_COMPILER_PROFILE');
if (is_string($raw) && '' !== trim($raw)) {
    $v = trim($raw);
    if (preg_match('/^\d+\.\d+$/', $v)) {
        $v .= '.0';
    }
    if (version_compare($v, '8.4.0', '<')) {
        die('skip asymmetric visibility disabled on pre-8.4 forward profile');
    }
}
?>
--FILE--
<?php
class C {
    public private(set) string $name = 'Alice';
}
echo "parsed\n";
echo (new C())->name, "\n";
--EXPECT--
parsed
Alice
