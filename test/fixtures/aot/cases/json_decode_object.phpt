--TEST--
AOT: json_decode() default assoc=false stdClass graph (issue #7188)
--FILE--
<?php
declare(strict_types=1);

$json = '{"a":1,"b":{"c":2}}';

$obj = json_decode($json);
echo ($obj instanceof stdClass) ? 'true' : 'false', "\n";
echo ($obj->b instanceof stdClass) ? 'true' : 'false', "\n";
echo $obj->b->c, "\n";
--EXPECT--
true
true
2
