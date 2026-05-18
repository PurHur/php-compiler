--TEST--
Web: isset() false when query key absent
--ENV--
QUERY_STRING=page=home
--FILE--
<?php
if (isset($_GET['name'])) {
    echo 'has name', "\n";
} else {
    echo "no name\n";
}
--EXPECT--
no name
