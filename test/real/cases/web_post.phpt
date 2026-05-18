--TEST--
Web: read form fields from $_POST
--ENV--
REQUEST_BODY=name=Ada&role=dev
--FILE--
<?php
echo 'Hello ', $_POST['name'], "\n";
echo 'role=', $_POST['role'], "\n";
--EXPECT--
Hello Ada
role=dev
