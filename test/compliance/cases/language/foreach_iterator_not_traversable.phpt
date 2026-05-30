--TEST--
foreach rejects non-iterable object (VM, #3234)
--FILE--
<?php
class Plain {}
foreach (new Plain() as $v) {
    echo $v;
}
--EXPECT_EXIT--
255
