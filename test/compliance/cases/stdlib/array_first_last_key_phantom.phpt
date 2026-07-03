--TEST--
stdlib array_first_key()/array_last_key() — not advertised on PHP 8.2 reference profile (#15539)
--FILE--
<?php
echo function_exists('array_first_key') ? "fail_first\n" : "ok_first\n";
echo function_exists('array_last_key') ? "fail_last\n" : "ok_last\n";
--EXPECT--
ok_first
ok_last
