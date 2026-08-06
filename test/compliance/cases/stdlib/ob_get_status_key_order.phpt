--TEST--
ob_get_status() key order name first (php-src output.c; #28153)
--FILE--
<?php
ob_start();
echo 'x';
$row = ob_get_status(false);
ob_end_clean();
echo implode(',', array_keys($row)), "\n";

ob_start();
ob_start();
echo 'y';
$full = ob_get_status(true);
ob_end_clean();
ob_end_clean();
foreach ($full as $i => $r) {
    echo $i, ':', implode(',', array_keys($r)), "\n";
}
--EXPECT--
name,type,flags,level,chunk_size,buffer_size,buffer_used
0:name,type,flags,level,chunk_size,buffer_size,buffer_used
1:name,type,flags,level,chunk_size,buffer_size,buffer_used
