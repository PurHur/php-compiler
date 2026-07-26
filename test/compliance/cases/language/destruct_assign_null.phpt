--TEST--
User __destruct on $obj=null and overwrite assignment (issue #23484, zend_objects.c)
--FILE--
<?php
class C {
    public function __construct(private string $n) {}
    public function __destruct() { echo "[D:{$this->n}]"; }
}
echo '1';
$a = new C('A');
echo '2';
$a = null;
echo '3';
$b = new C('B');
echo '4';
echo "\n";
$o = new C('X');
$o = new C('Y');
echo 'Z';
$o = null;
$b = null;
echo "\n";
--EXPECT--
12[D:A]34
[D:X]Z[D:Y][D:B]
