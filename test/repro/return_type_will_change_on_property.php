<?php
class C
{
    #[ReturnTypeWillChange]
    public $x = 1;
}
echo (new C())->x, "\n";
