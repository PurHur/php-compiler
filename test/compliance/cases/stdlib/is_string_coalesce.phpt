--TEST--
stdlib is_string() on ?? result (issue #148)
--ENV--
QUERY_STRING=id=Ada
--FILE--
<?php
$id = $_GET['id'] ?? '';
echo is_string($id) ? 'y' : 'n', "\n";
echo $id, "\n";
--EXPECT--
y
Ada
