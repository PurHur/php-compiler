--TEST--
AOT: parse_str() populates result array for routing params
--FILE--
<?php
$params = [];
parse_str('route=items&page=3', $params);
echo $params['route'], ':', $params['page'], "\n";
--EXPECT--
items:3
