<?php

declare(strict_types=1);

$h = fopen('php://memory', 'r+');
$id = get_resource_id($h);
$cast = (float) $h;
if ($cast !== (float) $id) {
    fwrite(STDERR, "expected float(resource_id), got {$cast}\n");
    exit(1);
}
echo "ok\n";
