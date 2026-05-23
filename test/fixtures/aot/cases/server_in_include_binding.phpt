--TEST--
AOT: inherited include binding after superglobal ?? (echo before coalesce, #866)
--ENV--
SCRIPT_NAME=/index.php
--RUNFILE--
server_in_include_binding/entry.php
--EXPECT--
Home
/index.php
--EXPECT_EXIT--
0
