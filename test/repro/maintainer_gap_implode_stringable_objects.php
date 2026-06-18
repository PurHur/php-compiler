<?php

class C
{
    public function __toString(): string
    {
        return 'x';
    }
}

var_export(implode('', [new C(), new C()]));
echo "\n";
var_export(join(',', [new C(), new C()]));
echo "\n";
