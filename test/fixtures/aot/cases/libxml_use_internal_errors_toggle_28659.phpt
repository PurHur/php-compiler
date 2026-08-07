--TEST--
AOT libxml_use_internal_errors() toggles like Zend (#28659)
--FILE--
<?php
$prev = libxml_use_internal_errors(true);
echo ($prev ? 't' : 'f');
$prev2 = libxml_use_internal_errors(true);
echo ($prev2 ? 't' : 'f');
$prev3 = libxml_use_internal_errors(false);
echo ($prev3 ? 't' : 'f');
$prev4 = libxml_use_internal_errors();
echo ($prev4 ? 't' : 'f');
--EXPECT--
fttf
