--TEST--
Web: web_int/web_string/web_bool param coercion (issue #157)
--ENV--
QUERY_STRING=page=abc&page2=42&page3=99999&name=%20%20Bob%20%20&flag=on&bad=yes
--FILE--
<?php
declare(strict_types=1);
echo web_int($_GET, 'page', 1), "\n";
echo web_int($_GET, 'page2', 1), "\n";
echo web_int($_GET, 'page3', 1, 1, 9999), "\n";
echo web_int($_GET, 'missing', 7), "\n";
echo web_string($_GET, 'name', ''), "\n";
echo web_string($_GET, 'name', '', 3), "\n";
echo web_bool($_GET, 'flag', false) ? 't' : 'f', "\n";
echo web_bool($_GET, 'bad', true) ? 't' : 'f', "\n";
echo web_bool($_GET, 'missing', true) ? 't' : 'f', "\n";
--EXPECT--
1
42
9999
7
Bob
Bob
t
t
t
