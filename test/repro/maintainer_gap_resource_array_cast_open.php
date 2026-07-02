<?php

declare(strict_types=1);

$h = fopen('php://memory', 'r+');
$open = (array) $h;
if (1 !== count($open) || !array_key_exists(0, $open) || !is_resource($open[0])) {
    fwrite(STDERR, "expected live resource at index 0\n");
    exit(1);
}
if ('stream' !== get_resource_type($open[0])) {
    fwrite(STDERR, "expected stream resource type\n");
    exit(1);
}
echo "ok\n";
