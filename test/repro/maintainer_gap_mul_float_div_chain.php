<?php

declare(strict_types=1);

$sprintfOut = sprintf('%.10F', 5 * 200.0 / 12);
if ('83.3333333333' !== $sprintfOut) {
    fwrite(STDERR, "sprintf: expected 83.3333333333 got {$sprintfOut}\n");
    exit(1);
}

$numberFormatOut = number_format(5 * 200.0 / 12, 2);
if ('83.33' !== $numberFormatOut) {
    fwrite(STDERR, "number_format: expected 83.33 got {$numberFormatOut}\n");
    exit(1);
}

$x = 5 * 200.0 / 12;
if (abs($x - 83.333333333333334) > 1e-10) {
    fwrite(STDERR, "assignment: expected ~83.33 got {$x}\n");
    exit(1);
}

echo "ok\n";
