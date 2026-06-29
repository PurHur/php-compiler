<?php

declare(strict_types=1);

stream_set_blocking(STDIN, false);
$blocked = stream_get_meta_data(STDIN)['blocked'];
if (false !== $blocked) {
    fwrite(STDERR, 'expected blocked=false after stream_set_blocking(STDIN, false), got ');
    var_export($blocked);
    fwrite(STDERR, "\n");
    exit(1);
}
echo "ok\n";
