--TEST--
stdlib BcMath\Number(float) float→int DEP+truncate (#24625, ext/bcmath)
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

$n = new Number(1.5);
echo '1.5 value=', var_export((string) $n, true), ' scale=', $n->scale, "\n";

$n = new Number(2.9);
echo '2.9 value=', var_export((string) $n, true), ' scale=', $n->scale, "\n";

$n = new Number(1.0);
echo '1.0 value=', var_export((string) $n, true), ' scale=', $n->scale, "\n";

$n = new Number(-2.7);
echo '-2.7 value=', var_export((string) $n, true), ' scale=', $n->scale, "\n";
--EXPECT--
DEP:Implicit conversion from float 1.5 to int loses precision
1.5 value='1' scale=0
DEP:Implicit conversion from float 2.9 to int loses precision
2.9 value='2' scale=0
1.0 value='1' scale=0
DEP:Implicit conversion from float -2.7 to int loses precision
-2.7 value='-2' scale=0
