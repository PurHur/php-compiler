--TEST--
Language: dim ++/+= on uninitialized typed array property Errors; assign/append still auto-init (#31784)
--FILE--
<?php
class C {
    public array $a;
}
function t(string $label, callable $fn): void {
    try {
        $fn();
        echo $label, "=ok\n";
    } catch (Throwable $e) {
        echo $label, '=', get_class($e), ':', $e->getMessage(), "\n";
    }
}
t('inc', function () {
    $o = new C;
    $o->a[0]++;
    var_export($o->a);
    echo "\n";
});
t('add', function () {
    $o = new C;
    $o->a[0] += 1;
    var_export($o->a);
    echo "\n";
});
t('preinc', function () {
    $o = new C;
    ++$o->a[0];
    var_export($o->a);
    echo "\n";
});
t('assign', function () {
    $o = new C;
    $o->a[0] = 1;
    var_export($o->a);
    echo "\n";
});
t('append', function () {
    $o = new C;
    $o->a[] = 2;
    var_export($o->a);
    echo "\n";
});
echo "after\n";
?>
--EXPECT--
inc=Error:Typed property C::$a must not be accessed before initialization
add=Error:Typed property C::$a must not be accessed before initialization
preinc=Error:Typed property C::$a must not be accessed before initialization
array (
  0 => 1,
)
assign=ok
array (
  0 => 2,
)
append=ok
after
