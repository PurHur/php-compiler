--TEST--
JsonSerializable interface exposes abstract jsonSerialize() (ext/json, #28561)
--FILE--
<?php
echo method_exists(JsonSerializable::class, 'jsonSerialize') ? 'me=y' : 'me=n', "\n";
$rc = new ReflectionClass(JsonSerializable::class);
$names = array_map(static fn(ReflectionMethod $m) => $m->getName(), $rc->getMethods());
echo 'methods=', json_encode($names), "\n";
$m = $rc->getMethod('jsonSerialize');
echo 'abs=', $m->isAbstract() ? 'y' : 'n', ' pub=', $m->isPublic() ? 'y' : 'n', "\n";
echo 'ret=', $m->hasReturnType() ? (string) $m->getReturnType() : 'NONE', "\n";
class C implements JsonSerializable {
    public function jsonSerialize(): mixed {
        return ['x' => 1];
    }
}
echo json_encode(new C), "\n";
--EXPECT--
me=y
methods=["jsonSerialize"]
abs=y pub=y
ret=mixed
{"x":1}
