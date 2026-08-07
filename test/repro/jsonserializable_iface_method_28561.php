<?php

declare(strict_types=1);

/**
 * JsonSerializable must expose abstract jsonSerialize for method_exists/Reflection (#28561).
 * php-src: ext/json/php_json.stub.php
 */
echo 'method_exists=', method_exists(JsonSerializable::class, 'jsonSerialize') ? 'y' : 'n', "\n";
$names = array_map(
    static fn(ReflectionMethod $m) => $m->getName(),
    (new ReflectionClass(JsonSerializable::class))->getMethods()
);
echo 'methods=', json_encode($names), "\n";
$m = (new ReflectionClass(JsonSerializable::class))->getMethod('jsonSerialize');
echo 'abs=', $m->isAbstract() ? 'y' : 'n', ' pub=', $m->isPublic() ? 'y' : 'n', "\n";
echo 'ret=', $m->hasReturnType() ? (string) $m->getReturnType() : 'NONE', "\n";
class C implements JsonSerializable
{
    public function jsonSerialize(): mixed
    {
        return ['x' => 1];
    }
}
echo json_encode(new C), "\n";
