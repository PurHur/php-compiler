<?php
class C
{
    public $a = 1.5;
    public $b = true;
    public $c = false;
}
echo serialize(new C()), "\n";
