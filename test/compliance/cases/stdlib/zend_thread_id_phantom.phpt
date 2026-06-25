--TEST--
stdlib zend_thread_id() — not advertised on PHP 8.2 reference profile (#11842)
--FILE--
<?php
echo function_exists('zend_thread_id') ? "fail\n" : "ok\n";
--EXPECT--
ok
