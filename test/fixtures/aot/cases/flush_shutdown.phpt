--TEST--
AOT: flush() with active ob buffer at script end (issue #3675)
--FILE--
<?php
ob_start();
echo 'chunk';
flush();
--EXPECT--
chunk
