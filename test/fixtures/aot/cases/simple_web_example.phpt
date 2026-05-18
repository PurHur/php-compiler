--TEST--
AOT: examples/001-SimpleWeb (compile-time $_GET)
--ENV--
QUERY_STRING=name=AOT
--FILE--
<?php
declare(strict_types=1);
$name = $_GET['name'];
header('Content-Type: text/html; charset=UTF-8');
echo '<!DOCTYPE html><html><body>';
echo '<h1>Hello ', htmlspecialchars($name), "</h1>\n";
echo '</body></html>';
--EXPECTF--
Content-Type: text/html; charset=UTF-8
<!DOCTYPE html><html><body><h1>Hello AOT</h1>
</body></html>
