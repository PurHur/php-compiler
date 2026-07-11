<?php
echo http_build_query(['a' => 'b c'], '', null, PHP_QUERY_RFC3986), "\n";
echo http_build_query(['a' => 'b c'], arg_separator: '&', encoding_type: PHP_QUERY_RFC3986), "\n";
echo http_build_query(['a' => 'b c'], encoding_type: PHP_QUERY_RFC3986), "\n";
