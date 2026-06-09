--TEST--
stdlib strip_tags() — array allowed_tags (#5053, ext/standard/string.c)
--FILE--
<?php
echo strip_tags('<a><b>x</b></a>', ['a']), "\n";
echo strip_tags('<b>x</b><i>y</i>', ['b', 'i']), "\n";
echo strip_tags('<p>a</p>', []), "\n";
--EXPECT--
<a>x</a>
<b>x</b><i>y</i>
a
