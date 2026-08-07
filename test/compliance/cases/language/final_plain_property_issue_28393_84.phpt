--TEST--
Language: #28393 issue-body — final public isFinal=1 + write OK under PROFILE=8.4 (Zend/zend_inheritance.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class A {
  final public string $x;
  public function __construct(string $x) { $this->x = $x; }
}
$a = new A("a");
try { $a->x = "b"; echo "wrote\n"; } catch (Throwable $e) { echo "write_err\n"; }
$r = new ReflectionProperty(A::class, "x");
echo "isFinal=", $r->isFinal() ? "1" : "0", "\n";
--EXPECT--
wrote
isFinal=1
