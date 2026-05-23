--TEST--
AOT: $_REQUEST ?? default with literal include binding (MiniWebApp hello, #784)
--RUNFILE--
coalesce_request_include/entry.php
--EXPECT--
World
