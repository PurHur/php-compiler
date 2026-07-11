<?php

declare(strict_types=1);

if ('integer' === gettype(hrtime(true))) {
    echo "skip — hrtime(true) integer on reference profile\n";
    exit(0);
}

$type = gettype(hrtime(true));
if ('double' !== $type) {
    fwrite(STDERR, "fail: hrtime(true) type is {$type} not float\n");
    exit(1);
}
$named = gettype(hrtime(as_number: true));
if ('double' !== $named) {
    fwrite(STDERR, "fail: hrtime(as_number: true) type is {$named} not float\n");
    exit(1);
}
echo "ok\n";
