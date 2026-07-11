--TEST--
stdlib http_build_query() legacy 3-arg int encoding_type (issue #13740)
--FILE--
<?php
echo http_build_query(['a b' => 1], '', PHP_QUERY_RFC3986), "\n";
echo http_build_query(['a b' => 1], '', '&', PHP_QUERY_RFC3986), "\n";
--EXPECT--
a+b=1
a%20b=1
