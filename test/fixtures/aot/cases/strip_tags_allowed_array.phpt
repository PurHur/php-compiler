--TEST--
AOT strip_tags() array allowed_tags (#5053)
--FILE--
<?php
echo strip_tags('<a><b>x</b></a>', ['a']), "\n";
--EXPECT--
<a>x</a>
