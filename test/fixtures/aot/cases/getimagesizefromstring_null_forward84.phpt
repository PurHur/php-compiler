--TEST--
AOT: getimagesizefromstring(null) soft-null → false on 8.4 (#21492, reverts #20353, ext/standard/image.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
// Return-value parity only: AOT currently omits DEP/notice emission for this builtin
// (pre-existing; same gap for getimagesizefromstring('')). VM/JIT cover DEP+notice.
$r = @getimagesizefromstring(null);
echo $r === false ? "false\n" : "other\n";
?>
--EXPECT--
false
