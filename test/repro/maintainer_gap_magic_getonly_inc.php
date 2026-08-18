<?php
error_reporting(E_ALL);
class GO {
    private $d = ['z' => 1];
    public function __get($k) {
        echo "get:$k\n";
        return $this->d[$k];
    }
}
$errs = [];
set_error_handler(static function (int $errno, string $errstr) use (&$errs): bool {
    $errs[] = [$errno, $errstr];
    return true;
});
$g = new GO();
$g->z++;
restore_error_handler();
foreach ($errs as $e) {
    echo "err[{$e[0]}] {$e[1]}\n";
}
echo "z=", $g->z, "\n";
