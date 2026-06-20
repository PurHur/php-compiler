--TEST--
stdlib call_user_func() string builtin callbacks JIT/AOT (issue #10359)
--FILE--
<?php
declare(strict_types=1);

echo call_user_func('strlen', 'abc'), "\n";
echo call_user_func_array('strlen', ['xyz']), "\n";
?>
--EXPECT--
3
3
