--TEST--
stdlib http_build_query() JIT null separator + RFC3986 + named args (issue #6092)
--FILE--
<?php
echo http_build_query(['a' => 'b c'], '', null, PHP_QUERY_RFC3986), "\n";
echo http_build_query(['a' => 'b c'], arg_separator: '&', encoding_type: PHP_QUERY_RFC3986), "\n";
echo http_build_query(['a' => 'b c'], encoding_type: PHP_QUERY_RFC3986), "\n";
--EXPECT--
a=b%20c
a=b%20c
a=b%20c
