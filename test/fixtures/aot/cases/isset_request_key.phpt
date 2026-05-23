--TEST--
AOT: isset($_REQUEST['name']) with QUERY_STRING (MiniWebApp hello, #767, #764)
--ENV--
QUERY_STRING=route=hello&name=Dev
--FILE--
<?php
declare(strict_types=1);
if (isset($_REQUEST['name'])) {
    echo 'yes:', $_REQUEST['name'], "\n";
} else {
    echo "no\n";
}
--EXPECT--
yes:Dev
