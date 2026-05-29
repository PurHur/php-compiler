--TEST--
AOT json_validate() — valid and invalid JSON (issue #3101)
--FILE--
<?php
echo json_validate('{"ok":true}') ? '1' : '0';
echo "\n";
echo json_validate('not json') ? '1' : '0';
echo "\n";
--EXPECT--
1
0
