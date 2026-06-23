--TEST--
stdlib realpath('') — empty path resolves to cwd like '.' (#10257)
--FILE--
<?php
$empty = realpath('');
$dot = realpath('.');
echo ($empty === $dot && is_string($empty) && '' !== $empty) ? "ok\n" : "fail\n";
--EXPECT--
ok
