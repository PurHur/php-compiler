--TEST--
AOT: json_decode() default assoc=false stdClass graph (issue #7188)
--FILE--
<?php
declare(strict_types=1);

$json = '{"a":1,"b":{"c":2}}';

$obj = json_decode($json);
var_export($obj instanceof stdClass);
echo "\n";
var_export($obj->b instanceof stdClass);
echo "\n";
echo $obj->b->c, "\n";
--EXPECT--
true
true
2
