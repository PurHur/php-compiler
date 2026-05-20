--TEST--
AOT: web_int/web_string with QUERY_STRING refresh (issue #157)
--ENV--
QUERY_STRING=page=abc&name=%20Ada%20
--FILE--
<?php
declare(strict_types=1);
echo web_int($_GET, 'page', 1), "\n";
echo web_string($_GET, 'name', ''), "\n";
--EXPECT--
1
Ada
