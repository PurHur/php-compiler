<?php

declare(strict_types=1);

/**
 * Maintainer repro: locale_get_* BCP-47 parsers (#5125).
 */

if (!\function_exists('locale_get_primary_language')) {
    echo "fail: locale_get_primary_language not registered\n";
    exit(1);
}
if (!\function_exists('locale_get_region')) {
    echo "fail: locale_get_region not registered\n";
    exit(1);
}
if (!\function_exists('locale_get_script')) {
    echo "fail: locale_get_script not registered\n";
    exit(1);
}

$checks = [
    ['en_US_POSIX', 'en', 'US', ''],
    ['zh-Hans-CN', 'zh', 'CN', 'Hans'],
    ['en', 'en', '', ''],
];

foreach ($checks as [$loc, $lang, $region, $script]) {
    if (locale_get_primary_language($loc) !== $lang) {
        echo "fail: primary {$loc}\n";
        exit(1);
    }
    if (locale_get_region($loc) !== $region) {
        echo "fail: region {$loc}\n";
        exit(1);
    }
    if (locale_get_script($loc) !== $script) {
        echo "fail: script {$loc}\n";
        exit(1);
    }
}

echo "ok\n";
