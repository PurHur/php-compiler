<?php
// Repro #35752 — subclass inherits typed DateTime property set in parent::__construct.
class Base {
    public DateTime $dt;
    public function __construct() {
        $this->dt = new DateTime('2020-01-01');
    }
}
class Sub extends Base {
    public function __construct() {
        parent::__construct();
    }
}
echo (new Sub)->dt->format('Y-m-d'), "\n";
