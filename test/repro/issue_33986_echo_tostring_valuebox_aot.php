<?php
class C {
    public function __toString(): string
    {
        return 'S';
    }
}
$o = new C;
echo $o;
echo '|';
print $o;
echo '|';
echo new C;
echo '|';
function f(C $x): void
{
    echo $x;
}
f(new C);
echo "\n";
