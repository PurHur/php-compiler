--TEST--
tidyNode + tidy_get_root/html/head/body registered (#21543)
--FILE--
<?php
echo (int) class_exists('tidyNode'), "\n";
echo (int) (new ReflectionClass('tidyNode'))->isFinal(), "\n";
echo (int) function_exists('tidy_get_root'), "\n";
echo (int) function_exists('tidy_get_html'), "\n";
echo (int) function_exists('tidy_get_head'), "\n";
echo (int) function_exists('tidy_get_body'), "\n";
echo (int) method_exists('tidy', 'root'), "\n";
echo (int) method_exists('tidy', 'html'), "\n";
echo (int) method_exists('tidy', 'head'), "\n";
echo (int) method_exists('tidy', 'body'), "\n";
echo (int) method_exists('tidyNode', 'hasChildren'), "\n";
echo (int) method_exists('tidyNode', 'isText'), "\n";
echo (int) method_exists('tidyNode', 'isComment'), "\n";
echo (int) method_exists('tidyNode', 'getParent'), "\n";
?>
--EXPECT--
1
1
1
1
1
1
1
1
1
1
1
1
1
1
