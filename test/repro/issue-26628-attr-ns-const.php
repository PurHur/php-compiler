<?php
namespace N;
#[\Attribute]
class Attr { public function __construct(public int $x) {} }
const C = 7;
#[Attr(C)]
function f() {}
echo (new \ReflectionFunction(__NAMESPACE__ . "\\f"))->getAttributes()[0]->newInstance()->x, "\n";
