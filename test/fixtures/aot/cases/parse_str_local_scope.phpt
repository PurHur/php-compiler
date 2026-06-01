--TEST--
AOT: parse_str() without $result binds {main} locals (issue #3708, #4034)
--FILE--
<?php
parse_str('id=42&name=Ada');
echo $id, ':', $name, "\n";
--EXPECT--
42:Ada
