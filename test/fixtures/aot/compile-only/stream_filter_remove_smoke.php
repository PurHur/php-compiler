<?php
// Compile-only (#6040): stream_filter_remove() VM builtin registration on AOT user-script path.
$fp = fopen('php://memory', 'w+');
$filter = stream_filter_append($fp, 'string.toupper', STREAM_FILTER_WRITE);
stream_filter_remove($filter);
fclose($fp);
