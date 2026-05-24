--TEST--
stdlib parse_str() for flat and nested query strings (issue #1367)
--FILE--
<?php
$result = [];
parse_str('a=1&b=2', $result);
echo $result['a'], ' ', $result['b'], "\n";

parse_str('user%5Bname%5D=Ada&tags%5B%5D=a&tags%5B%5D=b', $result);
echo $result['user']['name'], ' ', $result['tags'][0], ' ', $result['tags'][1], "\n";

$replace = ['existing' => 'stay'];
parse_str('new=1', $replace);
echo isset($replace['existing']) ? 'had-existing' : 'no-existing', ' ', $replace['new'], "\n";
--EXPECT--
1 2
Ada a b
no-existing 1
