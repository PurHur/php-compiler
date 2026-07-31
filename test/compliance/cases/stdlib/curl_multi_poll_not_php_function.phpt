--TEST--
curl_multi_poll is libcurl-only — must not appear in function_exists (php-src; #21826, #21834)
--ENV--
PHP_COMPILER_ENABLE_CURL=1
--FILE--
<?php
declare(strict_types=1);

// php-src ext/curl registers curl_multi_select(), not curl_multi_poll() (libcurl C API only).
$poll = function_exists('curl_multi_poll');
$select = function_exists('curl_multi_select');
echo 'poll=', (int) $poll, "\n";
echo 'select=', (int) $select, "\n";
--EXPECT--
poll=0
select=1
