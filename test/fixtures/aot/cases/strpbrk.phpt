--TEST--
AOT: strpbrk() via length-bounded LLVM (#27055)
--FILE--
<?php
echo strpbrk("hello", "aeiou"), "\n";
echo strpbrk("abc-def-ghi", "-"), "\n";
$miss = strpbrk("xyz", "aeiou");
echo ($miss === false ? "false" : "hit"), "\n";
--EXPECT--
ello
-def-ghi
false
