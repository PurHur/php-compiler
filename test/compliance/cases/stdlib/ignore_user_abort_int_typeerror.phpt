--TEST--
stdlib ignore_user_abort() — int operand must TypeError (#12715, ext/standard/basic_functions.c)
--FILE--
<?php
declare(strict_types=1);
try {
    ignore_user_abort(0);
    echo "fail\n";
} catch (TypeError $e) {
    echo str_contains($e->getMessage(), 'must be of type ?bool') ? "ok\n" : $e->getMessage();
}
--EXPECT--
ok
