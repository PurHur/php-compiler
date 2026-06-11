<?php

declare(strict_types=1);

if (!function_exists('date_sun_info')) {
    fwrite(STDERR, "MISSING date_sun_info\n");
    exit(1);
}

$info = date_sun_info(1718121600, 48.8566, 2.3522);
$keys = [
    'sunrise',
    'sunset',
    'transit',
    'civil_twilight_begin',
    'civil_twilight_end',
    'nautical_twilight_begin',
    'nautical_twilight_end',
    'astronomical_twilight_begin',
    'astronomical_twilight_end',
];
foreach ($keys as $key) {
    if (!array_key_exists($key, $info)) {
        fwrite(STDERR, "missing key: {$key}\n");
        exit(1);
    }
}
echo $info['sunrise'], "\n";
echo $info['sunset'], "\n";
echo $info['transit'], "\n";
