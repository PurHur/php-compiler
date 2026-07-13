--TEST--
stdlib file_get_contents() — data:// URI offset/length with inline concat (#18613, ext/standard/file.c)
--FILE--
<?php
$payload = '0123456789';
echo file_get_contents('data://text/plain,'.$payload, false, null, 3, 4), "\n";
?>
--EXPECT--
3456
