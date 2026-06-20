--TEST--
AOT call_user_func() string builtin callbacks (issue #10359)
--FILE--
<?php
declare(strict_types=1);

echo call_user_func('strlen', 'abc'), "\n";
$fn = 'strlen';
echo call_user_func($fn, 'xyz'), "\n";
?>
--EXPECT--
3
3
