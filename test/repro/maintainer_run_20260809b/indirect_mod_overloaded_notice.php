<?php
error_reporting(E_ALL);
$msgs = [];
set_error_handler(static function (int $n, string $m) use (&$msgs): bool {
    $msgs[] = ['errno' => $n, 'msg' => $m];
    return true;
});
class C {
    public function __get($n) { return []; }
    public function __set($n, $v) { echo "SET\n"; }
}
$o = new C;
try {
    $o->x[] = 1;
    echo "survived\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
echo 'warns=', json_encode($msgs), "\n";
