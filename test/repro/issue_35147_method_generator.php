<?php
// AOT: instance method generators — Call to undefined method C::g() (#35147)
class C
{
    public function g()
    {
        yield 1;
        yield 2;
    }
}
foreach ((new C())->g() as $v) {
    echo $v;
}
echo "\n";
