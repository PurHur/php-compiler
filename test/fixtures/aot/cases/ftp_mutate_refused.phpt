--TEST--
ftp_mkdir/delete/rename/rmdir NestedJIT AOT compile (#31427)
--FILE--
<?php
$c = @ftp_connect("127.0.0.1", 1, 1);
if ($c) {
    @ftp_login($c, "u", "p");
    var_dump(ftp_mkdir($c, "d"));
    var_dump(ftp_rename($c, "a", "b"));
    var_dump(ftp_delete($c, "b"));
    var_dump(ftp_rmdir($c, "d"));
    ftp_close($c);
} else {
    echo "refused\n";
}
--EXPECT--
refused
