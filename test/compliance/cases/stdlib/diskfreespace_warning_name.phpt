--TEST--
stdlib diskfreespace() — warning cites called alias not disk_free_space (ext/standard/filestat.c, #16358)
--FILE--
<?php
@diskfreespace('/no/such/phpc-diskfreespace-warning');
$err = error_get_last();
echo ($err !== null ? $err['message'] : 'no_error')."\n";

@disk_free_space('/no/such/phpc-diskfreespace-warning');
$err = error_get_last();
echo ($err !== null ? $err['message'] : 'no_error')."\n";
--EXPECT--
diskfreespace(): No such file or directory
disk_free_space(): No such file or directory
