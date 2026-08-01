<?php
#[Attribute]
class Attr { public function __construct(public int $x) {} }
const G = 4;
#[Attr(G)]
function f() {}
echo (new ReflectionFunction("f"))->getAttributes()[0]->newInstance()->x, "\n";
