<?php

declare(strict_types=1);

$class = __CLASS__;
if ('' !== $class) {
    fwrite(STDERR, "fail: __CLASS__ in global scope got '{$class}', want ''\n");
    exit(1);
}

echo "ok\n";
