--TEST--
AOT: isset() on $_GET with non-literal string key (issue #70)
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
--EXPECT_EXIT--
0
