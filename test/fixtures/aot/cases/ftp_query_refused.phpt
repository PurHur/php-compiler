--TEST--
ftp_size/mdtm/systype/nlist NestedJIT AOT compile (#31380)
--FILE--
<?php
$c = @ftp_connect("127.0.0.1", 1, 1);
if ($c) {
    @ftp_login($c, "u", "p");
    @ftp_pasv($c, true);
    var_dump(ftp_size($c, "x"));
    var_dump(ftp_mdtm($c, "x"));
    var_dump(ftp_systype($c));
    var_dump(ftp_nlist($c, "."));
    ftp_close($c);
} else {
    echo "refused\n";
}
--EXPECT--
refused
