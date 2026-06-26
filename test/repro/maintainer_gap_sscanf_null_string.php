<?php

$r = sscanf(null, '%d');
if (null !== $r) {
    echo "fail: sscanf(null) expected NULL got ", var_export($r, true), "\n";
    exit(1);
}
echo "ok\n";
