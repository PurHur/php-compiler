<?php

class C
{
}

$ru = new ReflectionClass(C::class);
$ri = new ReflectionClass(stdClass::class);

$su = (string) $ru;
$si = (string) $ri;

echo str_starts_with($su, 'Class [') ? 'user-ok' : 'user-bad', "\n";
echo (str_contains($su, ' class C ') || str_contains($su, ' class C]')) ? 'user-name' : 'user-noname', "\n";
echo str_contains($su, '<user>') ? 'user-tag' : 'user-notag', "\n";

echo str_starts_with($si, 'Class [') ? 'std-ok' : 'std-bad', "\n";
echo str_contains($si, 'stdClass') ? 'std-name' : 'std-noname', "\n";
echo str_contains($si, '<internal') ? 'std-tag' : 'std-notag', "\n";

echo $ru->__toString() === $su ? 'method-eq' : 'method-ne', "\n";
