<?php

declare(strict_types=1);

$type = gettype(hrtime(true));
if ('integer' !== $type) {
    fwrite(STDERR, "fail: hrtime(true) type is {$type} not integer\n");
    exit(1);
}
$named = gettype(hrtime(as_number: true));
if ('integer' !== $named) {
    fwrite(STDERR, "fail: hrtime(as_number: true) type is {$named} not integer\n");
    exit(1);
}
echo "ok\n";
