--TEST--
AOT: isset($_REQUEST['name']) inside class method (#767)
--ENV--
QUERY_STRING=route=hello&name=Dev
--RUNFILE--
isset_request_in_method/entry.php
--EXPECT--
Dev
