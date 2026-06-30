<?php

declare(strict_types=1);

$missing = '/nonexistent/phpc-copy-' . getmypid();
var_export(@copy($missing, sys_get_temp_dir() . '/dst.txt'));
echo "\n";
var_export(@touch('/nonexistent_parent_' . getmypid() . '/f.txt'));
echo "\n";
