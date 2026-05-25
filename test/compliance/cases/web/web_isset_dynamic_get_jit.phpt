--TEST--
Web: isset() on $_GET with variable string key after per-run refresh (issue #1901)
--ENV--
QUERY_STRING=name=World
--FILE--
<?php
$key = 'name';
if (isset($_GET[$key])) {
    echo 'Hello ', $_GET[$key], "\n";
} else {
    echo "Hello Guest\n";
}
--EXPECT--
Hello World
