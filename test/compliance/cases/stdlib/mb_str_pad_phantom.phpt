--TEST--
stdlib mb_str_pad() — withheld on 8.4.0-dev reference / PHP 8.2 profile (#11964, #21790, #22373)
--FILE--
<?php
echo function_exists('mb_str_pad') ? "fail\n" : "ok\n";
--EXPECT--
ok
