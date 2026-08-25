--TEST--
AOT: eval() inherits caller $this; static/file scope Errors (#31902, zend_eval_string)
--FILE--
<?php
class C31902 {
    public $x = 7;
    public function f() { return eval('return $this->x;'); }
    public function g() { eval('$this->x = 9;'); return $this->x; }
}
class S31902 {
    public static function f() {
        try { return eval('return $this->x;'); }
        catch (Throwable $e) { return get_class($e) . ': ' . $e->getMessage(); }
    }
}
$c = new C31902();
echo $c->f(), "\n";
echo $c->g(), "\n";
echo S31902::f(), "\n";
try {
    eval('return $this->x;');
    echo "file=OK\n";
} catch (Throwable $e) {
    echo 'file=', get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
7
9
Error: Using $this when not in object context
file=Error: Using $this when not in object context
--EXPECT_EXIT--
0
