<?php
// Repro #34135 — ReflectionClass::__toString thin AOT
class C34135
{
}

$r = new ReflectionClass(C34135::class);
$dump = (string) $r;
echo (str_starts_with($dump, 'Class [') ? 'ok_user' : 'bad_user'), "\n";
echo (str_contains($dump, 'class C34135') ? 'ok_name' : 'bad_name'), "\n";

$s = new ReflectionClass(stdClass::class);
$sd = (string) $s;
echo (str_starts_with($sd, 'Class [') ? 'ok_std' : 'bad_std'), "\n";
echo (str_contains($sd, 'stdClass') ? 'ok_std_name' : 'bad_std_name'), "\n";
