<?php
declare(strict_types=1);
// Maintainer gap probe: stream_filter_append($stream, null) under strict_types.
// Zend: TypeError Argument #2 ($filter_name) must be of type string, null given
// VM (2026-08-16): coerces null→"" then warns unable to locate filter ""
$h = fopen('php://memory', 'r+');
var_export(stream_filter_append($h, null));
echo "\n";
fclose($h);
