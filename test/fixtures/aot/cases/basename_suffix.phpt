--TEST--
AOT: basename() optional suffix argument
--FILE--
<?php
echo basename('/foo/bar.txt', '.txt'), "\n";
echo basename('/foo/bar.txt'), "\n";
--EXPECT--
bar
bar.txt
