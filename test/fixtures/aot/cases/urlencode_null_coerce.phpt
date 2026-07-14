--TEST--
AOT urlencode()/rawurlencode() null coerce on default profile (#18912, re-#18733, ext/standard/url.c)
--FILE--
<?php
foreach (['urlencode', 'rawurlencode'] as $fn) {
    echo $fn.': '.var_export($fn(null), true)."\n";
}
?>
--EXPECT--
urlencode: ''
rawurlencode: ''
