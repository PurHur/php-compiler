--TEST--
Language: die() two-argument form — status + message (#6718)
--FILE--
<?php
die(2, "done");
--EXPECT--
done
--EXPECT_EXIT--
2
