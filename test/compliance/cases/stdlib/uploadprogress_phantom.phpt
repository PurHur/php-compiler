--TEST--
stdlib uploadprogress withheld on default profile — Zend pecl absent (#26744)
--FILE--
<?php
declare(strict_types=1);

echo (int) extension_loaded('uploadprogress'), "\n";
echo (int) function_exists('uploadprogress_get_info'), "\n";
echo (int) function_exists('uploadprogress_get_contents'), "\n";
--EXPECT--
0
0
0
