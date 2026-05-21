--TEST--
AOT: PUT form body populates $_POST (issue #291)
--ENV--
REQUEST_METHOD=PUT
REQUEST_BODY=name=Ada&role=dev
CONTENT_TYPE=application/x-www-form-urlencoded
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
