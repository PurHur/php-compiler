<?php
/**
 * Inherited promoted ctor `new` default — subclass must run parent's init fragment (#6652 leftover).
 * php-src: Zend/zend_compile.c default_value, zend_objects.c property init.
 */
class ParentDt {
    public function __construct(public DateTime $dt = new DateTime('2021-06-15')) {}
}
class ChildDt extends ParentDt {}
echo 'direct:', (new ParentDt)->dt->format('Y-m-d'), "\n";
echo 'child:', (new ChildDt)->dt->format('Y-m-d'), "\n";

class Marker {
    public function __construct(public string $tag = 'builtin') {}
}
class ParentMarker {
    public function __construct(public Marker $m = new Marker('builtin')) {}
}
class ChildMarker extends ParentMarker {}
echo 'marker:', (new ChildMarker)->m->tag, "\n";
