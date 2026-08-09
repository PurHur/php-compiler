--TEST--
AOT: substr_compare(null $offset) soft-null coerce (#29504, ext/standard/string.c Z_PARAM_LONG)
--FILE--
<?php
// DEP is verified on VM/JIT; AOT checks coerce result (offset 0 → 'abc' vs 'b' → -1).
echo substr_compare('abc', 'b', null), "\n";
?>
--EXPECT--
-1
