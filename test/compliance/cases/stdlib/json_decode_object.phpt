--TEST--
stdlib json_decode() default assoc=false returns stdClass graph (issue #7188)
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

$empty = json_decode('{}');
var_export($empty instanceof stdClass);
echo "\n";
var_export((array) $empty === []);
echo "\n";

$arr = json_decode('[1,2]', true);
var_export(is_array($arr));
echo "\n";
echo $arr[0], $arr[1], "\n";
--EXPECT--
true
true
2
true
true
true
12
