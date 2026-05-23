--TEST--
AOT: $_REQUEST ?? then layout hello partial + htmlspecialchars (#764)
--ENV--
QUERY_STRING=name=Dev
--RUNFILE--
coalesce_request_layout_hello/entry.php
--EXPECT--
Hello Dev
