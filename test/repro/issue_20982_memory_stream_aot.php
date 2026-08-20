<?php

declare(strict_types=1);

// #20982 — php://memory fopen/fread/fwrite/rewind under thin AOT must compile (StreamIo ftellArgv).
$fh = fopen('php://memory', 'r+');
fwrite($fh, 'alpha');
rewind($fh);
echo fread($fh, 5), "\n";
fclose($fh);
