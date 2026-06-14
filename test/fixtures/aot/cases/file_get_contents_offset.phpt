--TEST--
AOT: file_get_contents() offset/maxlen (#4157)
--FILE--
<?php
$path = 'test/fixtures/aot/cases/file_get_contents_offset_target.txt';
file_put_contents($path, '<?php echo 1;');
echo file_get_contents($path, false, null, 0, 4), "\n";
echo file_get_contents($path, false, null, 5, 3), "\n";
unlink($path);
?>
--EXPECT--
<?ph
 ec
