<?php
// #6652 leftover — subclass parent::__construct() must apply promoted `new` ctor defaults.
class ParentDt36108 {
    public function __construct(public DateTime $dt = new DateTime('2021-06-15')) {}
}
class ChildCallsParent36108 extends ParentDt36108 {
    public function __construct()
    {
        parent::__construct();
    }
}
echo (new ChildCallsParent36108)->dt->format('Y-m-d'), "\n";
