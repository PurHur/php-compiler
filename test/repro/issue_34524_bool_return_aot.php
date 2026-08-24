<?php
// AOT: typed bool return from untyped arg must compile (#34524).
function f($x): bool
{
    return $x % 2 == 0;
}
var_dump(f(2), f(1));

class EvenFilter extends FilterIterator
{
    public function accept(): bool
    {
        return $this->current() % 2 == 0;
    }
}
$it = new EvenFilter(new ArrayIterator([1, 2, 3, 4]));
foreach ($it as $v) {
    echo $v;
}
echo "\n";
