--TEST--
stdlib nl2br() — scalar subject + Z_PARAM_BOOL use_xhtml (#4293)
--FILE--
<?php
var_export(nl2br(123));
echo "\n";
var_export(nl2br("a\nb", 0));
echo "\n";
var_export(nl2br("a\nb", "x"));
echo "\n";
var_export(nl2br("a\nb", "false"));
echo "\n";
var_export(nl2br("a\nb", "0"));
echo "\n";
var_export(nl2br("a\nb", null));
echo "\n";
try {
    echo nl2br("a\nb", []);
} catch (TypeError $e) {
    echo 'TypeError', "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
'123'
'a<br>
b'
'a<br />
b'
'a<br />
b'
'a<br>
b'
'a<br>
b'
TypeError
nl2br(): Argument #2 ($use_xhtml) must be of type bool, array given
