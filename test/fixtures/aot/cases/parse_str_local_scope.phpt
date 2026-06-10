--TEST--
AOT: parse_str() two-arg populates result array (#4050)
--FILE--
<?php
$params = [];
parse_str('id=42&name=Ada', $params);
echo $params['id'], ':', $params['name'], "\n";
--EXPECT--
42:Ada
