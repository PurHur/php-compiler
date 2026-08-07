<?php
// #28613: AOT instance-method first-class callable must bind $this and invoke.
class C
{
    public function i($x)
    {
        return $x + 1;
    }
}

$b = (new C())->i(...);
echo $b(3), "\n";
