--TEST--
AOT: web_int/web_string/web_bool with QUERY_STRING refresh (issue #157)
--ENV--
QUERY_STRING=page=abc&name=%20Ada%20&flag=on&bad=yes
--FILE--
<?php
declare(strict_types=1);
echo web_int($_GET, 'page', 1), "\n";
echo web_string($_GET, 'name', ''), "\n";
echo web_bool($_GET, 'flag', false), "\n";
echo web_bool($_GET, 'bad', true), "\n";
echo web_bool($_GET, 'missing', true), "\n";
--EXPECT--
1
Ada
1
1
1
