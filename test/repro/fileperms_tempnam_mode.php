<?php

declare(strict_types=1);

$f = tempnam(sys_get_temp_dir(), 'phpc');
if (false === $f) {
    fwrite(STDERR, "tempnam failed\n");
    exit(1);
}
$tail = substr(sprintf('%o', fileperms($f)), -3);
@unlink($f);
echo $tail, "\n";
exit('600' === $tail ? 0 : 1);
