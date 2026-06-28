<?php

declare(strict_types=1);

foreach (['STDIN', 'STDOUT', 'STDERR'] as $name) {
    echo $name, '=', defined($name) ? 'yes' : 'no', "\n";
}

if (defined('STDOUT')) {
    fprintf(STDOUT, "ok\n");
}
