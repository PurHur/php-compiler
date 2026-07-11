<?php

$r = version_compare(null, '1.0');
if (-1 !== $r) {
    echo "fail: version_compare(null) expected -1 got ", var_export($r, true), "\n";
    exit(1);
}
echo "ok\n";
