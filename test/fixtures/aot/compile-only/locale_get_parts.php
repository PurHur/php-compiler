<?php

declare(strict_types=1);

/**
 * AOT compile-only: locale_get_primary_language when ext/intl locale API is advertised (#5125).
 */
if (!\function_exists('locale_get_primary_language')) {
    echo "skip\n";
    exit(0);
}

echo locale_get_primary_language('en_US_POSIX'), "\n";
echo locale_get_region('en_US_POSIX'), "\n";
echo locale_get_script('zh-Hans-CN'), "\n";
