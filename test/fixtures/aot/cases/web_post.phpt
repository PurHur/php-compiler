--TEST--
AOT: read form fields from $_POST (runtime REQUEST_BODY refresh)
--ENV--
REQUEST_BODY=name=Ada&role=dev
--FILE--
<?php
$name = $_POST['name'];
$role = $_POST['role'];
echo 'Hello ', $name, "\n";
echo 'role=', $role, "\n";
--EXPECT--
Hello Ada
role=dev
--EXPECT_EXIT--
0
