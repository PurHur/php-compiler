--TEST--
stdlib imap phantom withhold on reference profile (#3663)
--FILE--
<?php
echo function_exists('imap_open') ? '1' : '0';
echo extension_loaded('imap') ? '1' : '0';
echo class_exists('IMAP\\Connection') ? '1' : '0';
?>
--EXPECT--
000
