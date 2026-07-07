--TEST--
stdlib locale_get_primary_language/region/script — BCP-47 parsing (#5125, ext/intl/locale/locale_methods.c)
--FILE--
<?php
declare(strict_types=1);

echo locale_get_primary_language('en_US_POSIX'), "\n";
echo locale_get_region('en_US_POSIX'), "\n";
echo locale_get_script('zh-Hans-CN'), "\n";
echo locale_get_primary_language('en'), "\n";
echo locale_get_region('en'), "\n";
echo locale_get_primary_language('zh-Hans-CN'), "\n";
echo locale_get_region('zh-Hans-CN'), "\n";
--EXPECT--
en
US
Hans
en

zh
CN
