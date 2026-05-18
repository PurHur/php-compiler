--TEST--
Web: read query parameter from $_GET
--ENV--
QUERY_STRING=name=World&page=home
--FILE--
<?php
echo 'Hello ', $_GET['name'], "\n";
echo 'page=', $_GET['page'], "\n";
--EXPECT--
Hello World
page=home
