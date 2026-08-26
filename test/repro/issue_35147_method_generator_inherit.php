<?php
// AOT: inherited instance method generator (#35147)
class A
{
    public function g()
    {
        yield 1;
        yield 2;
    }
}
class B extends A
{
}
foreach ((new B())->g() as $v) {
    echo $v;
}
echo "\n";
