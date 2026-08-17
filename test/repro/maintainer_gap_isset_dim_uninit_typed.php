<?php
class C {
    public array $a;
    public ?array $n;
    public string $s;
}
$o = new C;
echo 'isset_a0=', isset($o->a[0]) ? '1' : '0', "\n";
echo 'empty_a0=', empty($o->a[0]) ? '1' : '0', "\n";
echo 'isset_nk=', isset($o->n['k']) ? '1' : '0', "\n";
echo 'empty_nk=', empty($o->n['k']) ? '1' : '0', "\n";
echo 'coalesce_a0=';
var_export($o->a[0] ?? 'd');
echo "\n";
echo 'isset_s0=', isset($o->s[0]) ? '1' : '0', "\n";
echo 'empty_s0=', empty($o->s[0]) ? '1' : '0', "\n";
echo "after\n";
