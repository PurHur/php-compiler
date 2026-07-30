--TEST--
stdlib date_parse/getLastErrors HashTable key order matches php-src (#25485)
--FILE--
<?php
declare(strict_types=1);

echo implode('|', array_keys(date_parse('2020-01-02 03:04:05'))), "\n";
echo implode('|', array_keys(date_parse('2020-01-02 03:04:05 UTC'))), "\n";
echo implode('|', array_keys(date_parse('2024-01-01T12:00:00+02:00'))), "\n";

DateTime::createFromFormat('Y-m-d', 'not-a-date');
echo implode('|', array_keys(DateTime::getLastErrors())), "\n";
--EXPECT--
year|month|day|hour|minute|second|fraction|warning_count|warnings|error_count|errors|is_localtime
year|month|day|hour|minute|second|fraction|warning_count|warnings|error_count|errors|is_localtime|zone_type|tz_abbr|tz_id
year|month|day|hour|minute|second|fraction|warning_count|warnings|error_count|errors|is_localtime|zone_type|zone|is_dst
warning_count|warnings|error_count|errors
