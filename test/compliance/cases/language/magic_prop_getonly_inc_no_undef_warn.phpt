--TEST--
Language: $obj->n++ with __get only invokes __get, no Undefined property (#32016, zend_object_handlers.c)
--FILE--
<?php
error_reporting(E_ALL);
class GO {
    private $d = ['z' => 1];
    public function __get($k) { return $this->d[$k]; }
}
$errs = [];
set_error_handler(static function (int $errno, string $errstr) use (&$errs): bool {
    if (E_WARNING === $errno && str_contains($errstr, 'Undefined property')) {
        $errs[] = [$errno, $errstr];
    }
    return true;
});
$g = new GO();
$g->z++;
restore_error_handler();
foreach ($errs as $e) {
    echo "err[{$e[0]}] {$e[1]}\n";
}
echo "inc=", $g->z, "\n";
--EXPECT--
inc=2
