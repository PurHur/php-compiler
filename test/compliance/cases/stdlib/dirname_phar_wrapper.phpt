--TEST--
stdlib dirname() phar:// wrapper trailing slash — phar: not phar:/ (#11026, ext/standard/basic_functions.c)
--FILE--
<?php
echo dirname('phar://archive.phar/internal'), "\n";
echo dirname('phar://archive.phar/'), "\n";
echo dirname('phar://archive.phar'), "\n";
echo dirname('php://memory'), "\n";
--EXPECT--
phar://archive.phar
phar:
phar:
php:
