--TEST--
stdlib isset() on $_GET with non-literal string key (issue #70)
--ENV--
QUERY_STRING=name=Ada
--FILE--
<?php
$key = 'name';
if (isset($_GET[$key])) {
    echo $_GET[$key], "\n";
} else {
    echo "missing\n";
}
$missing = 'absent';
if (!isset($_GET[$missing])) {
    echo "absent ok\n";
}
--EXPECT--
Ada
absent ok
