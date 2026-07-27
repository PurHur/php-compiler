--TEST--
Language: attributes on namespace-scope constants under PROFILE=8.5 (#23882)
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
namespace App;
#[\Attribute]
class Marker {}
#[Marker]
const MARKED = 7;
echo MARKED, "\n";
$r = new \ReflectionConstant('App\\MARKED');
echo count($r->getAttributes()), "\n";
echo $r->getAttributes()[0]->getName(), "\n";
--EXPECT--
7
1
Marker
