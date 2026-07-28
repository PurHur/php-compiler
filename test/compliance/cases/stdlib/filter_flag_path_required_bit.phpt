--TEST--
filter FILTER_FLAG_PATH_REQUIRED is 0x40000 like Zend (ext/filter/filter_private.h, #24108)
--FILE--
<?php
declare(strict_types=1);

echo FILTER_FLAG_PATH_REQUIRED === 262144 ? 'const_ok' : 'const_bad', "\n";
echo false === filter_var('https://example.com', FILTER_VALIDATE_URL, 262144) ? 'numeric_ok' : 'numeric_bad', "\n";
echo false === filter_var('https://example.com', FILTER_VALIDATE_URL, FILTER_FLAG_PATH_REQUIRED) ? 'named_ok' : 'named_bad', "\n";
echo 'https://example.com/path' === filter_var('https://example.com/path', FILTER_VALIDATE_URL, 262144) ? 'path_ok' : 'path_bad', "\n";
echo FILTER_FLAG_QUERY_REQUIRED === 524288 ? 'query_ok' : 'query_bad', "\n";
--EXPECT--
const_ok
numeric_ok
named_ok
path_ok
query_ok
