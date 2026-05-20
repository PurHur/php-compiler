--TEST--
Language: double-quoted string interpolation (issue #261)
--FILE--
<?php
$name = 'Dev';
echo "Hello, $name\n";

$route = '/api';
echo "Route: {$route}\n";

$user = ['id' => 42];
echo "id={$user['id']}\n";

$empty = null;
echo "null=|$empty|\n";

class C {
    public $x = 9;
}
$o = new C;
echo "prop={$o->x}\n";
--EXPECT--
Hello, Dev
Route: /api
id=42
null=||
prop=9
