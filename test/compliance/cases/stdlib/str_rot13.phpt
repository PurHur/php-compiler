--TEST--
stdlib str_rot13()
--FILE--
<?php
echo str_rot13(''), "\n";
echo str_rot13('PHP'), "\n";
echo str_rot13('CUC'), "\n";
echo str_rot13('hello'), "\n";
echo str_rot13('uryyb'), "\n";
echo str_rot13('123'), "\n";
echo str_rot13('n-apple-z'), "\n";
--EXPECT--

CUC
PHP
uryyb
hello
123
a-nccyr-m
