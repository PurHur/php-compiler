--TEST--
stdlib json_validate() — not advertised on PHP 8.2 reference profile (#11826)
--FILE--
<?php
echo function_exists('json_validate') ? "fail\n" : "ok\n";
--EXPECT--
ok
