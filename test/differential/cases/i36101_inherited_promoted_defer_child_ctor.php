<?php
// Subclass __construct without parent::__construct leaves ancestor promoted slots unset (#6652 leftover).
class ParentPromotedDefer36101 {
    public function __construct(public DateTime $dt = new DateTime('2021-06-15'), public int $n = 5) {}
}
class ChildPromotedDefer36101 extends ParentPromotedDefer36101 {
    public function __construct(public string $tag = 'child') {}
}
$c = new ChildPromotedDefer36101();
try {
    echo $c->dt->format('Y-m-d'), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo $c->tag, "\n";
