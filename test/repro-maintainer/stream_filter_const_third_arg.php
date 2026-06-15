<?php
// #6040 / #3283: ConstFetch as 3rd arg must not steal ARG_SEND slot for $stream (#8624 follow-up).
$fp = fopen('php://memory', 'w+');
$filter = stream_filter_append($fp, 'string.toupper', STREAM_FILTER_WRITE);
var_export(stream_filter_remove($filter));
echo "\n";
