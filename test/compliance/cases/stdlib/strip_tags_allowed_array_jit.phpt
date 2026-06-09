--TEST--
stdlib strip_tags() — array allowed_tags JIT/AOT (#5053)
--FILE--
<?php
echo strip_tags('<a><b>x</b></a>', ['a']), "\n";
echo strip_tags('<b>x</b><i>y</i>', ['b', 'i']), "\n";
--EXPECT--
<a>x</a>
<b>x</b><i>y</i>
