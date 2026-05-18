--TEST--
stdlib isset() and fetch on string-keyed hashtable (_GET)
--ENV--
QUERY_STRING=name=Ada
--FILE--
<?php
if (isset($_GET['name'])) {
    echo $_GET['name'], "\n";
} else {
    echo "missing\n";
}
--EXPECT--
Ada
