--TEST--
ftp_close / ftp_quit NestedJIT AOT connect+close (#31377)
--FILE--
<?php
$c = @ftp_connect("127.0.0.1", 1, 1);
if ($c) {
    var_dump(ftp_close($c));
} else {
    echo "refused\n";
}
$c2 = @ftp_connect("127.0.0.1", 1, 1);
if ($c2) {
    var_dump(ftp_quit($c2));
} else {
    echo "refused\n";
}
--EXPECT--
refused
refused
