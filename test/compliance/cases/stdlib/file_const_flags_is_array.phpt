--TEST--
stdlib file() — variable and const FILE_* flags with is_array consumer (#10474, ext/standard/file.c)
--FILE--
<?php
$tmp = tempnam(sys_get_temp_dir(), 'f');
file_put_contents($tmp, "a\nb\nc\n");
$f = 6;
echo is_array(file($tmp, 6)) ? '1' : '0';
echo is_array(file($tmp, $f)) ? '1' : '0';
echo is_array(file($tmp, FILE_SKIP_EMPTY_LINES | FILE_IGNORE_NEW_LINES)) ? '1' : '0';
unlink($tmp);
--EXPECT--
111
