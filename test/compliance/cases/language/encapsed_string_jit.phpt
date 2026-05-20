--TEST--
Language: double-quoted string interpolation JIT (issue #261)
--FILE--
<?php
$name = 'Dev';
echo "Hello, $name\n";

$route = '/api';
echo "Route: {$route}\n";

$user = ['id' => 42];
echo "id={$user['id']}\n";
--EXPECT--
Hello, Dev
Route: /api
id=42
