<?php

declare(strict_types=1);

$path = sys_get_temp_dir();
echo function_exists('disk_total_space') ? 'total=yes' : 'total=no', "\n";
echo function_exists('disktotalspace') ? 'alias=yes' : 'alias=no', "\n";
if (function_exists('disktotalspace')) {
    $direct = disk_total_space($path);
    $alias = disktotalspace($path);
    echo ($direct === $alias) ? 'equal=yes' : 'equal=no', "\n";
}
