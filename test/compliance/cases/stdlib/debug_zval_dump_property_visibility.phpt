--TEST--
stdlib debug_zval_dump() — private/protected property key presentation (#23741, ext/standard/var.c)
--FILE--
<?php
class O {
    public $a = 1;
    private $b = 2;
    protected $c = 3;
}
ob_start();
debug_zval_dump(new O());
$out = ob_get_clean();
echo str_contains($out, '["b":"O":private]=>') ? "private=ok\n" : "private=fail\n";
echo str_contains($out, '["c":protected]=>') ? "protected=ok\n" : "protected=fail\n";
echo str_contains($out, "\0") ? "nul=fail\n" : "nul=ok\n";
--EXPECT--
private=ok
protected=ok
nul=ok
