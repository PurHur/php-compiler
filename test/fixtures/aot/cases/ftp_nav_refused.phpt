--TEST--
ftp_pasv/chdir/cdup/pwd NestedJIT AOT compile (#31379)
--FILE--
<?php
$c = @ftp_connect("127.0.0.1", 1, 1);
if ($c) {
    @ftp_login($c, "u", "p");
    var_dump(ftp_pasv($c, true));
    var_dump(ftp_chdir($c, "/"));
    var_dump(ftp_pwd($c));
    var_dump(ftp_cdup($c));
    ftp_close($c);
} else {
    echo "refused\n";
}
--EXPECT--
refused
