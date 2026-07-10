<?php

class ParentMethod
{
    public function label(): string
    {
        return 'parent';
    }
}

class ChildMethod extends ParentMethod
{
    public function viaFcc(): string
    {
        $f = parent::label(...);

        return $f();
    }
}

echo (new ChildMethod())->viaFcc(), "\n";
