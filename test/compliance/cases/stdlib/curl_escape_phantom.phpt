--TEST--
stdlib curl_escape() / curl_unescape() — not advertised without ext/curl (#13588)
--FILE--
<?php
declare(strict_types=1);

$phantom = function_exists('curl_escape') || function_exists('curl_unescape');
echo $phantom ? "fail\n" : "ok\n";
--EXPECT--
ok
