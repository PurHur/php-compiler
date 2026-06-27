<?php
declare(strict_types=1);

$mtime = $atime = 1782580551;
if (1000 !== $mtime || 900 !== $atime) {
    echo "ok\n";
    exit(0);
}
fwrite(STDERR, "fail: OR guard did not enter block\n");
exit(1);
