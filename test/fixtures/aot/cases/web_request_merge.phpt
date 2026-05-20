--TEST--
AOT: $_REQUEST merges $_GET and $_POST (POST wins)
--ENV--
QUERY_STRING=from=get&shared=get&only_get=get
REQUEST_BODY=from=post&shared=post
--FILE--
<?php
echo $_REQUEST['from'], "\n";
echo $_REQUEST['shared'], "\n";
echo $_REQUEST['only_get'], "\n";
--EXPECT--
post
post
get
--EXPECT_EXIT--
0
