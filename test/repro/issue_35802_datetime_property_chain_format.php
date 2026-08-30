<?php
// Repro #35802 — cross-class chained typed-date property ->format() after prior date call.
class BaseDt {
    public DateTime $dt;
    public function __construct() {
        $this->dt = new DateTime('2020-01-01');
    }
}
class SubDt extends BaseDt {
    public function __construct() {
        parent::__construct();
    }
}
class BaseDit {
    public DateTimeImmutable $dt;
    public function __construct() {
        $this->dt = new DateTimeImmutable('2021-02-02');
    }
}
class SubDit extends BaseDit {
    public function __construct() {
        parent::__construct();
    }
}
echo (new DateTimeImmutable('2022-03-03'))->format('Y-m-d'), "\n";
echo (new SubDt)->dt->format('Y-m-d'), "\n";
echo (new SubDit)->dt->format('Y-m-d'), "\n";
