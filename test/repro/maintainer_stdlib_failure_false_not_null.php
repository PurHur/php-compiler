<?php

declare(strict_types=1);

$missing = '/no/such/phpc-maintainer-'.getmypid();
var_export(@stat($missing));
echo "\n";
var_export(@copy($missing, sys_get_temp_dir().'/dst.txt'));
echo "\n";
var_export(@touch('/nonexistent_parent_'.getmypid().'/f.txt'));
echo "\n";
var_export(@fopen($missing, 'r'));
echo "\n";
