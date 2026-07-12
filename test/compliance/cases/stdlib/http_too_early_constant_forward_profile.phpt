--TEST--
stdlib HTTP_TOO_EARLY constant — forward PHP 8.4 profile (#18059, ext/standard/http.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo defined('HTTP_TOO_EARLY') ? 'defined=1' : 'defined=0', "\n";
echo constant('HTTP_TOO_EARLY'), "\n";
$standard = get_defined_constants(true)['standard'] ?? [];
echo isset($standard['HTTP_TOO_EARLY']) ? 'standard=1' : 'standard=0', "\n";
echo $standard['HTTP_TOO_EARLY'] ?? 'missing', "\n";
--EXPECT--
defined=1
425
standard=1
425
