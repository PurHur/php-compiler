<?php

declare(strict_types=1);

echo sleep(null), "\n";
var_export(@usleep(null));
echo "\n";
var_export(time_nanosleep(null, 0));
echo "\n";
