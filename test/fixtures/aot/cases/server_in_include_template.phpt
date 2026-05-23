--TEST--
AOT: $_SERVER read in runtime-included template (layout.php SCRIPT_NAME, #866)
--ENV--
SCRIPT_NAME=/index.php
--RUNFILE--
server_in_include_template/entry.php
--EXPECT--
Content-Type: text/html; charset=UTF-8
/index.php
--EXPECT_EXIT--
0
