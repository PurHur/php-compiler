<?php

$ao = new ArrayObject(['' => 1]);
$exists = $ao->offsetExists(null);
$get = $ao->offsetGet(null);
if (true !== $exists) {
    echo 'fail: offsetExists(null) expected true, got ', var_export($exists, true), "\n";
    exit(1);
}
if (1 !== $get) {
    echo 'fail: offsetGet(null) expected 1, got ', var_export($get, true), "\n";
    exit(1);
}
echo "ok\n";
