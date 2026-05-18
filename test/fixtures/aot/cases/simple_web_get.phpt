--TEST--
AOT: web page with $_GET baked at compile time (examples/001-SimpleWeb subset)
--ENV--
QUERY_STRING=name=Compiled
--FILE--
<?php
$name = $_GET['name'];
header('Content-Type: text/html; charset=UTF-8');
echo '<!DOCTYPE html><html><body>';
echo '<h1>Hello ', htmlspecialchars($name), "</h1>\n";
echo '</body></html>';
--EXPECTF--
Content-Type: text/html; charset=UTF-8
<!DOCTYPE html><html><body><h1>Hello Compiled</h1>
</body></html>
--EXPECT_EXIT--
0
