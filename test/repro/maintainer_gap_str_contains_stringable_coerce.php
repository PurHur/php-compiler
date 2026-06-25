<?php

class StringableObj
{
    public function __toString(): string
    {
        return 'hello world';
    }
}

$obj = new StringableObj();
var_export(str_contains($obj, 'lo'));
echo "\n";
var_export(str_starts_with($obj, 'hel'));
echo "\n";
var_export(str_ends_with($obj, 'rld'));
echo "\n";
