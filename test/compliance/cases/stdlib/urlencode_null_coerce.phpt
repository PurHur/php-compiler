--TEST--
stdlib urlencode()/rawurlencode() null coerces to empty string (#18368, ext/standard/url.c)
--FILE--
<?php
echo 'urlencode=' . urlencode(null) . "\n";
echo 'rawurlencode=' . rawurlencode(null) . "\n";
--EXPECT--
urlencode=
rawurlencode=
