--TEST--
AOT: renderHello isset($_REQUEST) after resolveAppName (MiniWebApp #784, #764) @group miniwebapp-bisect
--ENV--
REQUEST_METHOD=GET
QUERY_STRING=name=Dev
--RUNFILE--
render_hello_request_assign/entry.php
--EXPECT--
Content-Type: text/html; charset=UTF-8
Hello Dev
--EXPECT_EXIT--
0
