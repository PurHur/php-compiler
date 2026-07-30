--TEST--
Language: implements ArrayAccess + ReflectionMethod stubs (Zend/zend_interfaces.c; #25425)
--FILE--
<?php
class A implements ArrayAccess {
    public $d = [];
    public function offsetExists($o) { return isset($this->d[$o]); }
    public function offsetGet($o) { return $this->d[$o]; }
    public function offsetSet($o, $v) {
        if ($o === null) { $this->d[] = $v; } else { $this->d[$o] = $v; }
    }
    public function offsetUnset($o) { unset($this->d[$o]); }
}
$a = new A();
$a[] = 'x';
$a[] = 'y';
var_export($a->d);
echo "\n";

$m = new ReflectionMethod('ArrayAccess', 'offsetSet');
echo 'params=', $m->getNumberOfParameters(), "\n";
foreach ($m->getParameters() as $p) {
    echo $p->getName(), ':', (string) $p->getType(), "\n";
}
$e = new ReflectionMethod('ArrayAccess', 'offsetExists');
echo 'exists=', $e->getParameters()[0]->getName(), ':', (string) $e->getParameters()[0]->getType(), "\n";
--EXPECT--
array (
  0 => 'x',
  1 => 'y',
)
params=2
offset:mixed
value:mixed
exists=offset:mixed
