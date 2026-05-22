--TEST--
Web: re-read $_GET after mutation in the same request
--ENV--
QUERY_STRING=x=initial
--FILE--
<?php
echo 'before=', $_GET['x'], "\n";
$_GET['x'] = 'mutated';
echo 'after=', $_GET['x'], "\n";
--EXPECT--
before=initial
after=mutated
