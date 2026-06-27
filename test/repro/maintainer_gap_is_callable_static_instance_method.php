<?php

declare(strict_types=1);

if (is_callable([Exception::class, 'getMessage'])) {
    fwrite(STDERR, "fail: is_callable([Exception::class, getMessage]) returned true\n");
    exit(1);
}
if (!is_callable([new Exception('x'), 'getMessage'])) {
    fwrite(STDERR, "fail: is_callable([object, getMessage]) returned false\n");
    exit(1);
}
if (is_callable(['stdClass', '__construct'])) {
    fwrite(STDERR, "fail: is_callable([stdClass, __construct]) returned true\n");
    exit(1);
}

echo "ok\n";
