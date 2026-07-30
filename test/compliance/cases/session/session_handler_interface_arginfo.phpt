--TEST--
session SessionHandlerInterface Reflection arginfo + implements LSP (ext/session/session.stub.php; #25426)
--FILE--
<?php
$r = new ReflectionClass(SessionHandlerInterface::class);
foreach ($r->getMethods() as $m) {
    $params = [];
    foreach ($m->getParameters() as $p) {
        $t = $p->hasType() ? (string) $p->getType() : '-';
        $params[] = $p->getName() . ':' . $t;
    }
    $rt = $m->hasReturnType() ? (string) $m->getReturnType() : '-';
    echo $m->getName(), '(', implode(',', $params), ') ret=', $rt, "\n";
}

class H25426 implements SessionHandlerInterface {
    public function open($path, $name): bool { return true; }
    public function close(): bool { return true; }
    public function read($id): string|false { return ''; }
    public function write($id, $data): bool { return true; }
    public function destroy($id): bool { return true; }
    public function gc($max_lifetime): int|false { return 0; }
}
echo "impl_ok\n";

$u = new ReflectionClass(SessionUpdateTimestampHandlerInterface::class);
foreach ($u->getMethods() as $m) {
    $params = [];
    foreach ($m->getParameters() as $p) {
        $t = $p->hasType() ? (string) $p->getType() : '-';
        $params[] = $p->getName() . ':' . $t;
    }
    echo 'tsu_', $m->getName(), '(', implode(',', $params), ")\n";
}
--EXPECT--
open(path:string,name:string) ret=-
close() ret=-
read(id:string) ret=-
write(id:string,data:string) ret=-
destroy(id:string) ret=-
gc(max_lifetime:int) ret=-
impl_ok
tsu_validateId(id:string)
tsu_updateTimestamp(id:string,data:string)
