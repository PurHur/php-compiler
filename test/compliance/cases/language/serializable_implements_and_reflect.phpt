--TEST--
Language: implements Serializable + ReflectionMethod stubs (Zend/zend_interfaces.c; #25406)
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(function ($no, $msg) {
    if ($no === E_DEPRECATED) {
        echo "dep\n";
        return true;
    }
    echo "err:$no:$msg\n";
    return true;
});

class S implements Serializable {
    public function serialize(): string { return ""; }
    public function unserialize(string $data): void {}
}
echo "loaded\n";

$u = new ReflectionMethod('Serializable', 'unserialize');
echo 'params=', $u->getNumberOfParameters(), "\n";
$p = $u->getParameters()[0];
echo 'pname=', $p->getName(), ' ptype=', (string) $p->getType(), "\n";
echo 'hasRet=', $u->hasReturnType() ? '1' : '0', "\n";
$dump = (string) $u;
echo str_contains($dump, 'string $data') ? "dump_ok\n" : ("dump_bad:\n".$dump);

$s = new ReflectionMethod('Serializable', 'serialize');
echo 'ser_params=', $s->getNumberOfParameters(), "\n";
echo (string) $s !== '' ? "ser_dump_ok\n" : "ser_dump_empty\n";
--EXPECT--
dep
loaded
params=1
pname=data ptype=string
hasRet=0
dump_ok
ser_params=0
ser_dump_ok
