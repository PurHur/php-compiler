--TEST--
Web: read form fields from $_POST
--POST--
name=Ada&role=dev
--FILE--
<?php
echo 'Hello ', $_POST['name'], "\n";
echo 'role=', $_POST['role'], "\n";
--EXPECT--
Hello Ada
role=dev
