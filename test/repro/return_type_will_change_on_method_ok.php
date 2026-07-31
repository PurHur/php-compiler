<?php
class C
{
    #[ReturnTypeWillChange]
    public function m()
    {
        return 1;
    }
}
echo (new C())->m(), "\n";
