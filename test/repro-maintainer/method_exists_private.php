<?php

class P
{
    private function m()
    {
    }
}

class C extends P
{
}

echo method_exists('C', 'm') ? '1' : '0';
echo method_exists(new C(), 'm') ? '1' : '0';
echo "\n";
