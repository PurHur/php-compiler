--TEST--
AOT: read form fields from $_POST (compile-time REQUEST_BODY via -p)
--ENV--
REQUEST_BODY=name=Ada&role=dev
--FILE--
<?php
echo 'Hello ', $_POST['name'], "\n";
echo 'role=', $_POST['role'], "\n";
--EXPECT--
Hello Ada
role=dev
--EXPECT_EXIT--
0
