--TEST--
AOT: inherited promoted ctor `new` default applies on subclass new (#6652 leftover, zend_objects.c)
--FILE--
<?php
class ParentDt {
    public function __construct(public DateTime $dt = new DateTime('2021-06-15')) {}
}
class ChildDt extends ParentDt {}
echo (new ParentDt)->dt->format('Y-m-d'), "\n";
echo (new ChildDt)->dt->format('Y-m-d'), "\n";
--EXPECT--
2021-06-15
2021-06-15
--EXPECT_EXIT--
0
