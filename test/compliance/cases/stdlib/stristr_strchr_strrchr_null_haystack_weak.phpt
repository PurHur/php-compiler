--TEST--
stdlib stristr/strchr/strrchr() — null $haystack coerces without strict_types (#29783, ext/standard/string.c)
--FILE--
<?php
foreach (['stristr', 'strchr', 'strrchr'] as $fn) {
    echo $fn, ':';
    var_export($fn(null, 'a'));
    echo "\n";
}
--EXPECT--
stristr:false
strchr:false
strrchr:false
