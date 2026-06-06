--TEST--
Language: exit()/die() two-argument form — status + message (#6718)
--FILE--
<?php
exit(1, "bye");
--EXPECT--
bye
--EXPECT_EXIT--
1
