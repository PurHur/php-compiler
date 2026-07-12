--TEST--
AOT: basename() optional suffix argument
--FILE--
<?php
echo basename('/foo/bar.txt', '.txt'), "\n";
echo basename('/foo/bar.txt'), "\n";
echo basename('/a/dir', 'dir'), "\n";
--EXPECT--
bar
bar.txt
dir
