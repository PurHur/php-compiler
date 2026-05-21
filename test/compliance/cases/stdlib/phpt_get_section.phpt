--TEST--
PHPT --GET-- populates $_GET without --ENV-- QUERY_STRING (issue #102)
--GET--
name=Ada&page=home
--FILE--
<?php
echo $_GET['name'], '|', $_GET['page'], "\n";
--EXPECT--
Ada|home
