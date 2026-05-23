--TEST--
AOT: isset $_REQUEST then include inherited guestName (#764)
--ENV--
QUERY_STRING=name=Dev
--RUNFILE--
isset_request_before_include/entry.php
--EXPECT--
Hello Dev
