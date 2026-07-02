<?php
$h = fopen('php://memory', 'r+');
$open = (array) $h;
if (!is_resource($open[0])) {
    fwrite(STDERR, 'expected live resource at index 0, got '.gettype($open[0])."\n");
    exit(1);
}
if ('stream' !== get_resource_type($open[0])) {
    fwrite(STDERR, 'expected stream type, got '.get_resource_type($open[0])."\n");
    exit(1);
}
echo "ok\n";
