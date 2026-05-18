--TEST--
Web: isset() on $_GET query parameters
--ENV--
QUERY_STRING=name=World
--FILE--
<?php
if (isset($_GET['name'])) {
    echo 'Hello ', $_GET['name'], "\n";
} else {
    echo "Hello Guest\n";
}
if (!isset($_GET['missing'])) {
    echo "missing ok\n";
}
--EXPECT--
Hello World
missing ok
