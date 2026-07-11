<?php

declare(strict_types=1);

$ai = new ArrayIterator(['' => 1]);
$exists = $ai->offsetExists(null);
$get = $ai->offsetGet(null);
if (true !== $exists) {
    echo 'fail: offsetExists(null) expected true, got ', var_export($exists, true), "\n";
    exit(1);
}
if (1 !== $get) {
    echo 'fail: offsetGet(null) expected 1, got ', var_export($get, true), "\n";
    exit(1);
}
$ifaces = class_implements($ai);
if (!isset($ifaces['ArrayAccess'])) {
    echo 'fail: class_implements missing ArrayAccess', "\n";
    exit(1);
}
echo "ok\n";
