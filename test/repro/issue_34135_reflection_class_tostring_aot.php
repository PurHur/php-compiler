<?php
// Repro #34135 — ReflectionClass::__toString thin AOT
class C34135
{
}

echo (string) new ReflectionClass(C34135::class);
echo "----\n";
echo (string) new ReflectionClass(stdClass::class);
