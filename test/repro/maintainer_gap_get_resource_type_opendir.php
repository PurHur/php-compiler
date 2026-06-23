<?php

declare(strict_types=1);

$dh = opendir(sys_get_temp_dir());
if (false === $dh) {
    fwrite(STDERR, "opendir failed\n");
    exit(1);
}
echo get_resource_type($dh), "\n";
closedir($dh);
