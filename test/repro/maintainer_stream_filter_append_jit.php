<?php
declare(strict_types=1);

$fp = fopen('php://temp', 'w+');
stream_filter_append($fp, 'string.toupper', STREAM_FILTER_WRITE);
fwrite($fp, 'abc');
rewind($fp);
echo stream_get_contents($fp), "\n";
