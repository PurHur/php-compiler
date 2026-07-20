--TEST--
tidy release/html_ver/is_xhtml/is_xml registered (#21542)
--FILE--
<?php
echo (int) function_exists('tidy_get_release'), "\n";
echo (int) function_exists('tidy_get_html_ver'), "\n";
echo (int) function_exists('tidy_is_xhtml'), "\n";
echo (int) function_exists('tidy_is_xml'), "\n";
echo (int) method_exists('tidy', 'getRelease'), "\n";
echo (int) method_exists('tidy', 'getHtmlVer'), "\n";
echo (int) method_exists('tidy', 'isXhtml'), "\n";
echo (int) method_exists('tidy', 'isXml'), "\n";
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
