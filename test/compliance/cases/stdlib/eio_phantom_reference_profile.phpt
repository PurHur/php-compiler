--TEST--
stdlib eio phantom withhold on reference profile (#6442)
--FILE--
<?php
echo function_exists('eio_init') ? '1' : '0';
echo extension_loaded('eio') ? '1' : '0';
?>
--EXPECT--
00
