--TEST--
foreach on non-iterable object — empty loop, exit 0 (PHP 8.2+, #3234)
--FILE--
<?php
class Plain {}
foreach (new Plain() as $v) {
    echo $v;
}
--EXPECT_EXIT--
0
