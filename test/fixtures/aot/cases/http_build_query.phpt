--TEST--
AOT: http_build_query() redirect-style params
--FILE--
<?php
$next = '/home';
$params = ['route' => 'items', 'page' => 3];
$qs = http_build_query($params);
echo $next, '?', $qs, "\n";
--EXPECT--
/home?route=items&page=3
