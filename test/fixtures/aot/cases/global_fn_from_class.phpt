--TEST--
AOT: global user function from class method (contact validation, #831, #764)
--RUNFILE--
global_fn_from_class/entry.php
--ENV--
REQUEST_METHOD=POST
QUERY_STRING=name=Dev
--EXPECT--
thanks:Dev
