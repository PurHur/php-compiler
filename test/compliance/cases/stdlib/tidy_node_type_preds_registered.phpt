--TEST--
tidyNode isHtml/isJste/isAsp/isPhp registered (#21606)
--FILE--
<?php
echo (int) method_exists('tidyNode', 'isHtml'), "\n";
echo (int) method_exists('tidyNode', 'isJste'), "\n";
echo (int) method_exists('tidyNode', 'isAsp'), "\n";
echo (int) method_exists('tidyNode', 'isPhp'), "\n";
?>
--EXPECT--
1
1
1
1
