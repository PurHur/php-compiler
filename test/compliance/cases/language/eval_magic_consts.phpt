--TEST--
language eval() __DIR__/__FILE__/__LINE__ match Zend enclosing script (issue #25809)
--FILE--
<?php
$dir = eval("return __DIR__;");
$file = eval("return __FILE__;");
$line = eval("return __LINE__;");
echo "DIR_MATCH=", ($dir === __DIR__) ? "yes" : "no", "\n";
echo "LINE=", $line, "\n";
$suffix = " : eval()'d code";
echo "FILE_SUFFIX=", (substr($file, -strlen($suffix)) === $suffix) ? "yes" : "no", "\n";
echo "FILE_HAS_CALL_LINE=", (preg_match('/\(\d+\) : eval\(\)\'d code$/', $file) === 1) ? "yes" : "no", "\n";
$line2 = eval("
return __LINE__;
");
echo "LINE2=", $line2, "\n";
--EXPECT--
DIR_MATCH=yes
LINE=1
FILE_SUFFIX=yes
FILE_HAS_CALL_LINE=yes
LINE2=2
