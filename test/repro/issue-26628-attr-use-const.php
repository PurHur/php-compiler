<?php
namespace Other;
const X = 9;
namespace N;
use const Other\X as Alias;
#[\Attribute]
class Attr { public function __construct(public int $x) {} }
#[Attr(Alias)]
function f() {}
echo (new \ReflectionFunction(__NAMESPACE__ . "\\f"))->getAttributes()[0]->newInstance()->x, "\n";
