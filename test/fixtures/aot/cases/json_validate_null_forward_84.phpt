--TEST--
AOT json_validate(null) — soft-null false on 8.4 forward profile (#28333, reverts #27995)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
// Const-null folds like json_decode (#21223): DEP on stderr (AotTest ignores), return false.
$r = json_validate(null);
echo $r === false ? "false\n" : "other\n";
echo json_validate('{}') ? '1' : '0';
echo "\n";
echo json_validate('{') ? '1' : '0';
echo "\n";
?>
--EXPECT--
false
1
0
