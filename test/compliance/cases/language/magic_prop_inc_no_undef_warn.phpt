--TEST--
Language: $obj->n++ with __get/__set emits no Undefined property (#31992, zend_object_handlers.c)
--FILE--
<?php
error_reporting(E_ALL);
class M {
    private $d = ['n' => 1];
    public function __get($k) { return $this->d[$k]; }
    public function __set($k, $v) { $this->d[$k] = $v; }
}
$errs = [];
set_error_handler(static function (int $errno, string $errstr) use (&$errs): bool {
    $errs[] = [$errno, $errstr];
    return true;
});
$m = new M();
$m->n++;
restore_error_handler();
foreach ($errs as $e) {
    echo "err[{$e[0]}] {$e[1]}\n";
}
echo "inc=", $m->n, "\n";
$m = new M();
$m->n += 2;
echo "plus=", $m->n, "\n";
--EXPECT--
inc=2
plus=3
