--TEST--
stdlib flush() with active ob buffer at script end (issue #3675)
--FILE--
<?php
ob_start();
echo 'chunk';
flush();
echo ob_get_level();
--EXPECT--
chunk1
