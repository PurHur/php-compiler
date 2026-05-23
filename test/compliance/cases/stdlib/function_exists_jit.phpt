--TEST--
stdlib function_exists() JIT/AOT (issue #1216)
--FILE--
<?php
declare(strict_types=1);
function jit_user_fn(): void
{
}
function helper(): int
{
    return 1;
}
$name = 'header_remove';
echo function_exists('strlen') ? "1\n" : "0\n";
echo function_exists($name) ? "1\n" : "0\n";
echo function_exists('jit_user_fn') ? "1\n" : "0\n";
echo function_exists('helper') ? "1\n" : "0\n";
echo function_exists('missing_fn_xyz') ? "1\n" : "0\n";
--EXPECT--
1
1
1
1
0
