<?php

declare(strict_types=1);

$inline = date_sun_info(strtotime('2020-06-21'), 51.5, -0.1);
$t = strtotime('2020-06-21');
$var = date_sun_info($t, 51.5, -0.1);

if ($inline['sunrise'] === $var['sunrise'] && $inline['sunset'] === $var['sunset']) {
    echo "ok\n";
    exit(0);
}

echo 'fail inline_sunrise=', var_export($inline['sunrise'], true),
    ' var_sunrise=', var_export($var['sunrise'], true), "\n";
exit(1);
