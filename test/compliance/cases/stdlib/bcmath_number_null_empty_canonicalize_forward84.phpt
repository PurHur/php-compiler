--TEST--
stdlib BcMath\Number(null|"") soft-null + canonicalize to "0" (#24140, ext/bcmath)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
use BcMath\Number;

if (!class_exists(Number::class, false)) {
    echo "skip: BcMath\\Number missing\n";
    exit(0);
}

error_reporting(E_ALL);
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo 'DEP:', $msg, "\n";

        return true;
    }

    return false;
});

$n = new Number(null);
$v = $n->value;
$s = $n->scale;
echo 'null value=', var_export($v, true), ' scale=', var_export($s, true), "\n";

$n = new Number('');
$v = $n->value;
$s = $n->scale;
echo 'empty value=', var_export($v, true), ' scale=', var_export($s, true), "\n";

$n = new Number('00.00');
$v = $n->value;
$s = $n->scale;
echo 'zeros value=', var_export($v, true), ' scale=', var_export($s, true), "\n";

$n = new Number('1.50');
$v = $n->value;
$s = $n->scale;
echo 'keep value=', var_export($v, true), ' scale=', var_export($s, true), "\n";
--EXPECT--
DEP:BcMath\Number::__construct(): Passing null to parameter #1 ($num) of type string|int is deprecated
null value='0' scale=0
empty value='0' scale=0
zeros value='0.00' scale=2
keep value='1.50' scale=2
