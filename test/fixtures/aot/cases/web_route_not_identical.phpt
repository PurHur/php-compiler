--TEST--
AOT: $_GET route guard with !== (MiniWebApp dispatch pattern)
--GET--
route=other
--FILE--
<?php
$route = $_GET['route'];
if ($route !== 'home') {
    echo "miss\n";
} else {
    echo "match\n";
}
--EXPECT--
miss
