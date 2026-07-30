--TEST--
Language: trait visibility adaptation + class method override (#25577, Zend/zend_traits.c)
--FILE--
<?php
trait T25577 {
    public function f() { return 'T'; }
    public function g() { return 'G'; }
}
class C25577 {
    use T25577 { g as private; }
    public function g() { return 'C'; }
}
$c = new C25577();
echo $c->f(), ',', $c->g(), PHP_EOL;

class C25577VisOnly {
    use T25577 { g as private; }
    public function call() { return $this->g(); }
}
$v = new C25577VisOnly();
echo $v->f(), ',', $v->call(), PHP_EOL;

class C25577Rename {
    use T25577 { f as h; }
}
$r = new C25577Rename();
echo $r->f(), ',', $r->h(), ',', $r->g(), PHP_EOL;
?>
--EXPECT--
T,C
T,G
T,T,G
