--TEST--
AOT: nested layout include reads $_REQUEST name (MiniWebApp hello, #878)
--ENV--
QUERY_STRING=route=hello&name=Dev
SCRIPT_NAME=/index.php
--RUNFILE--
miniwebapp_hello_nested/entry.php
--EXPECTREGEX--
Hello Dev
--EXPECT_EXIT--
0
