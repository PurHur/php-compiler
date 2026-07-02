<?php
$h = fopen('php://memory', 'r+');
fclose($h);
$closed = (array) $h;
if ('resource (closed)' !== gettype($closed[0])) {
    fwrite(STDERR, 'expected closed resource at index 0, got '.gettype($closed[0])."\n");
    exit(1);
}
if ('Unknown' !== get_resource_type($closed[0])) {
    fwrite(STDERR, 'expected Unknown type, got '.get_resource_type($closed[0])."\n");
    exit(1);
}
echo "ok\n";
