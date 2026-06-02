--TEST--
Return-by-reference method AOT (issue #4054)
--FILE--
<?php
class Box {
    public int $v = 0;
    public function &val(): int {
        return $this->v;
    }
}
$box = new Box();
$slot = &$box->val();
$slot = 7;
echo $box->v, "\n";
--EXPECT--
7
