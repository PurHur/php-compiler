--TEST--
ftp_login NestedJIT AOT connect+login+close (#31378)
--FILE--
<?php
$c = @ftp_connect("127.0.0.1", 1, 1);
if ($c) {
    var_dump(ftp_login($c, "u", "p"));
    var_dump(ftp_close($c));
} else {
    echo "refused\n";
}
--EXPECT--
refused
