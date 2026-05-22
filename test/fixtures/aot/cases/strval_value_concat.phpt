--TEST--
AOT: strval() on boxed __value__ at end of function (MiniWebApp concat, issue #58)
--ENV--
QUERY_STRING=name=Dev
--FILE--
<?php
declare(strict_types=1);
$name = $_REQUEST['name'];
echo 'Hello ', (string) $name, "\n";
--EXPECT--
Hello Dev
--EXPECT_EXIT--
0
