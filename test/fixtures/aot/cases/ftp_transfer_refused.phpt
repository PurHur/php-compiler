--TEST--
ftp_get/put/fget/fput NestedJIT AOT compile (#31429)
--FILE--
<?php
$c = @ftp_connect("127.0.0.1", 1, 1);
if ($c) {
    @ftp_login($c, "u", "p");
    @ftp_pasv($c, true);
    var_dump(ftp_get($c, "/tmp/phpc_ftp_get.bin", "r.bin"));
    var_dump(ftp_put($c, "w.bin", "/tmp/phpc_ftp_put.bin"));
    $fp = fopen("php://memory", "r+");
    var_dump(ftp_fget($c, $fp, "r.bin"));
    rewind($fp);
    var_dump(ftp_fput($c, "w2.bin", $fp));
    fclose($fp);
    ftp_close($c);
} else {
    echo "refused\n";
}
--EXPECT--
refused
