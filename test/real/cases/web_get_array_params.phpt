--TEST--
Web: array query parameters (tags[]=a&user[name]=Ada)
--ENV--
QUERY_STRING=tags[]=a&tags[]=b&user[name]=Ada
--FILE--
<?php
echo implode(',', $_GET['tags']), "\n";
echo $_GET['user']['name'], "\n";
--EXPECT--
a,b
Ada
