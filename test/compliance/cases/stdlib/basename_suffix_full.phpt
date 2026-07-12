--TEST--
stdlib basename() — suffix equals trailing name returns basename (#18111, ext/standard/basename.c)
--FILE--
<?php
echo basename('/a/dir', 'dir'), "\n";
echo basename('/a/b/c.txt', '.txt'), "\n";
--EXPECT--
dir
c
