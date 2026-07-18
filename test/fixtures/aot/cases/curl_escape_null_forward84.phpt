--TEST--
AOT: curl_escape(null) — TypeError on 8.4 forward profile (#20695, ext/curl/interface.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
// JIT/AOT encode path does not require a live CurlHandle (curl_init is VM-only; #6322).
$h = 0;
curl_escape($h, null);
--EXPECT--
--EXPECT_EXIT--
255
