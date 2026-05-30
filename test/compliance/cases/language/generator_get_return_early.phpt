--TEST--
Generator getReturn() before close throws (issue #3350)
--FILE--
<?php
function gen(): Generator {
    yield 1;
    return 99;
}
$g = gen();
$g->getReturn();
--EXPECT_EXIT--
255
