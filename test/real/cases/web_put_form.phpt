--TEST--
Web: PUT form body populates $_POST when Content-Type is form-urlencoded
--ENV--
REQUEST_METHOD=PUT
REQUEST_BODY=name=Ada&role=dev
CONTENT_TYPE=application/x-www-form-urlencoded
--FILE--
<?php
echo 'name=', $_POST['name'], "\n";
echo 'role=', $_POST['role'], "\n";
--EXPECT--
name=Ada
role=dev
