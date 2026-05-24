--TEST--
AOT: foreach $_GET with non-literal string keys (issue #86)
--ENV--
QUERY_STRING=a=1&b=2
--FILE--
<?php
$keys = ['a', 'b'];
foreach ($keys as $k) {
    if (isset($_GET[$k])) {
        echo $_GET[$k];
    }
}
--EXPECT--
12
