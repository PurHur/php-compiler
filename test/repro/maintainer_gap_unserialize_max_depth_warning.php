<?php

declare(strict_types=1);

$payload = 'a:1:{i:0;a:1:{i:0;a:1:{i:0;i:1;}}}';

$result = unserialize($payload, ['max_depth' => 1]);
if (false !== $result) {
    echo "FAIL: expected false\n";
    exit(1);
}

echo "ok\n";
exit(0);
