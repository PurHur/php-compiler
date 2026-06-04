<?php
#[Attribute]
class SomeAttr {
    public function __construct(public object $o) {}
}
#[SomeAttr(new stdClass())]
class C {}
var_dump((new ReflectionClass(C::class))->getAttributes()[0]->newInstance()->o);
