<?php

declare(strict_types=1);

class C
{
    public function __toString(): string
    {
        return 'obj';
    }
}

var_export(str_contains(new C(), 'obj'));
var_export(str_starts_with(new C(), 'obj'));
var_export(str_ends_with(new C(), 'obj'));
