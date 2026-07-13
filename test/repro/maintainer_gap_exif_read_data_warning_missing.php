<?php

declare(strict_types=1);

$path = __FILE__;
$result = exif_read_data($path);
var_export($result);
echo "\n";
$last = error_get_last();
echo isset($last['message']) ? $last['message'] : 'no-error';
echo "\n";
