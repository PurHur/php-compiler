--TEST--
stdlib urlencode()/rawurlencode() null coerce on default profile JIT (#18912, re-#18733, ext/standard/url.c)
--JIT--
--FILE--
<?php
foreach (['urlencode', 'rawurlencode'] as $fn) {
    echo $fn.': '.var_export($fn(null), true)."\n";
}
?>
--EXPECT--
urlencode: ''
rawurlencode: ''
