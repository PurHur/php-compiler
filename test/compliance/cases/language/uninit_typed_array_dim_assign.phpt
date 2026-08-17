--TEST--
Language: dim-assign/append on uninitialized typed array property auto-inits [] (#31770)
--FILE--
<?php
class C {
    public array $a;
    public ?array $n;
    public string $s;
    public int $i;
}
$o = new C;
$o->a[0] = 1;
echo 'idx=';
var_export($o->a);
echo "\n";
$o2 = new C;
$o2->a[] = 3;
echo 'append=';
var_export($o2->a);
echo "\n";
$o3 = new C;
$o3->n[] = 2;
echo 'npush=';
var_export($o3->n);
echo "\n";
try {
    $o4 = new C;
    $o4->s .= 'x';
    echo "string_concat=ok\n";
} catch (Error $e) {
    echo 'string_concat=', $e->getMessage(), "\n";
}
try {
    $o5 = new C;
    $o5->i += 1;
    echo "int_add=ok\n";
} catch (Error $e) {
    echo 'int_add=', $e->getMessage(), "\n";
}
try {
    $o6 = new C;
    echo $o6->a;
    echo "bare=ok\n";
} catch (Error $e) {
    echo 'bare=', $e->getMessage(), "\n";
}
?>
--EXPECT--
idx=array (
  0 => 1,
)
append=array (
  0 => 3,
)
npush=array (
  0 => 2,
)
string_concat=Typed property C::$s must not be accessed before initialization
int_add=Typed property C::$i must not be accessed before initialization
bare=Typed property C::$a must not be accessed before initialization
