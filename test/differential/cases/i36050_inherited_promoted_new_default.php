<?php
// #6652 leftover — subclass inherits promoted ctor `new DateTime(...)` default init fragment.
class ParentDt36050 {
    public function __construct(public DateTime $dt = new DateTime('2021-06-15')) {}
}
class ChildDt36050 extends ParentDt36050 {}
echo (new ChildDt36050)->dt->format('Y-m-d'), "\n";
