<?php
class C
{
    public private(set) string $x = 'a';
}
$c = new C();
echo $c->x, "\n";
