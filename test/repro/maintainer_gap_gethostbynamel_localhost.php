<?php

declare(strict_types=1);

$ips = gethostbynamel('localhost');
if (false === $ips) {
    fwrite(STDERR, "gethostbynamel(localhost) failed\n");
    exit(1);
}

echo 'count=', \count($ips), "\n";
foreach ($ips as $ip) {
    echo $ip, "\n";
}
