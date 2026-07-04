--TEST--
AOT: implode() — separator object TypeError abort (#16215, ext/standard/string.c)
--FILE--
<?php
class ImplodeBadSeparator {}
implode(new ImplodeBadSeparator(), []);
--EXPECT--
--EXPECT_EXIT--
134
