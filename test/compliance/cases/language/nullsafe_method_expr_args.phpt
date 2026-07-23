--TEST--
Language: nullsafe ?-> method call must pass expression/call args (#22660, Zend/zend_compile.c)
--FILE--
<?php
class A {
    public function take($x, $y = 'd') {
        return get_debug_type($x).':'.var_export($x, true).';y='.var_export($y, true);
    }
}
function make(string $label): string { echo "make:$label\n"; return $label; }
$a = new A();
echo $a?->take(make('N')), "\n";
echo $a?->take(make('A'), make('B')), "\n";
$v = 'lit';
echo $a?->take('lit'), "\n";
echo $a?->take($v), "\n";
$null = null;
echo "null-recv\n";
var_export($null?->take(make('S')));
echo "\n";
var_export($null?->take(make('T'), make('U')));
echo "\n";
--EXPECT--
make:N
string:'N';y='d'
make:A
make:B
string:'A';y='B'
string:'lit';y='d'
string:'lit';y='d'
null-recv
NULL
NULL
