--TEST--
AOT: examples/001-SimpleWeb via $_REQUEST (GET query, issue #259)
--ENV--
QUERY_STRING=name=RequestGet
--FILE--
<?php
declare(strict_types=1);
$name = $_REQUEST['name'];
header('Content-Type: text/html; charset=UTF-8');
echo '<h1>Hello ', htmlspecialchars($name), "</h1>\n";
--EXPECTF--
Content-Type: text/html; charset=UTF-8
<h1>Hello RequestGet</h1>
--EXPECT_EXIT--
0
