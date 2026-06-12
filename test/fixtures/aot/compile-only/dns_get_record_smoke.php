<?php
$r = dns_get_record('localhost', DNS_A);
echo is_array($r) ? "arr\n" : "false\n";
if (is_array($r)) {
    echo $r[0]['type'], "\n";
}
