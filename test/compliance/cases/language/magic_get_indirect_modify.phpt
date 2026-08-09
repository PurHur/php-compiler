--TEST--
Language: indirect modification of overloaded __get property is E_NOTICE (#29231, re-#4673)
--FILE--
<?php
error_reporting(E_ALL);
$msgs = [];
set_error_handler(static function (int $n, string $m) use (&$msgs): bool {
    $msgs[] = ['errno' => $n, 'msg' => $m];
    return true;
});
class Bag {
    private array $store = ['a' => 1];
    public function __get(string $name): array {
        return $this->store;
    }
}
$b = new Bag();
try {
    $b->store[] = 2;
    echo "survived1\n";
} catch (Throwable $err) {
    echo get_class($err), ': ', $err->getMessage(), "\n";
}
class Ext {
    private array $internal = [0];
    public function __get(string $name): array {
        return $this->internal;
    }
}
$ext = new Ext();
try {
    $ext->data['k'] = 9;
    echo "survived2\n";
} catch (Throwable $ex) {
    echo get_class($ex), ': ', $ex->getMessage(), "\n";
}
echo 'warns=', json_encode($msgs), "\n";
--EXPECT--
survived1
survived2
warns=[{"errno":8,"msg":"Indirect modification of overloaded property Bag::$store has no effect"},{"errno":8,"msg":"Indirect modification of overloaded property Ext::$data has no effect"}]
