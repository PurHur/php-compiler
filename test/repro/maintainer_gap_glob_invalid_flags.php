<?php

declare(strict_types=1);

$result = @glob('*', 99999);
if (false !== $result) {
    fwrite(STDERR, 'fail: expected false got '.var_export($result, true)."\n");
    exit(1);
}

echo "ok\n";
