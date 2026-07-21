--TEST--
AOT: curl_setopt(null option) — ValueError without live CurlHandle (#21878, ext/curl/interface.c)
--FILE--
<?php
// JIT/AOT invalid-option path does not require a live CurlHandle (curl_init is VM-only; #6322).
$h = 0;
curl_setopt($h, null, 0);
--EXPECT--
--EXPECT_EXIT--
255
