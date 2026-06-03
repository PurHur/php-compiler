--TEST--
stdlib nl2br() — use_xhtml bool coercion + TypeError (#5056)
--FILE--
<?php
var_export(nl2br("a\nb", "0"));
echo "\n";
var_export(nl2br("a\nb", false));
echo "\n";
try {
    echo nl2br("a\nb", []);
} catch (TypeError $e) {
    echo 'TypeError', "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
'a<br>
b'
'a<br>
b'
TypeError
nl2br(): Argument #2 ($use_xhtml) must be of type bool, array given
