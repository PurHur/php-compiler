--TEST--
Web: $_GET route guard with === (issue #90)
--ENV--
QUERY_STRING=route=home
--FILE--
<?php
$route = $_GET['route'];
if ($route === 'home') {
    echo "match\n";
} else {
    echo "miss\n";
}
--EXPECT--
match
