--TEST--
AOT str_rot13()
--FILE--
<?php
echo str_rot13('PHP'), "\n";
echo str_rot13('CUC'), "\n";
echo str_rot13('hello'), "\n";
echo str_rot13('123'), "\n";
--EXPECT--
CUC
PHP
uryyb
123
