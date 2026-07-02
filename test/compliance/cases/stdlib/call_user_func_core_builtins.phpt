--TEST--
stdlib call_user_func() string callbacks — count/gc_collect_cycles/array_sum (#14829)
--FILE--
<?php
declare(strict_types=1);

echo call_user_func('strlen', 'ab'), "\n";
echo call_user_func('count', [1, 2]), "\n";
echo call_user_func('gc_collect_cycles'), "\n";
echo call_user_func('array_sum', [1, 2]), "\n";
echo call_user_func('is_array', []) ? 'true' : 'false', "\n";
?>
--EXPECT--
2
2
0
3
true
