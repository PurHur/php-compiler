--TEST--
AOT: $_REQUEST POST name with REQUEST_METHOD=POST (#878)
--ENV--
REQUEST_METHOD=POST
QUERY_STRING=route=contact
REQUEST_BODY=name=PostDev
--FILE--
<?php
echo $_REQUEST['name'] ?? 'missing', "\n";
--EXPECT--
PostDev
--EXPECT_EXIT--
0
