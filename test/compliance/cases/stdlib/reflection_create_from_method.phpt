--TEST--
ReflectionMethod::createFromMethodName() static factory (#7038)
--FILE--
<?php
declare(strict_types=1);

class Rcfm {
    public function inst(int $x): int {
        return $x * 2;
    }

    public static function stat(int $a, int $b): int {
        return $a + $b;
    }
}

echo 'createFromMethodName=', var_export(method_exists('ReflectionMethod', 'createFromMethodName'), true), "\n";

$r = ReflectionMethod::createFromMethodName(Rcfm::class . '::inst');
echo $r->getName(), "\n";
echo $r->isPublic() ? "public\n" : "other\n";

$rs = ReflectionMethod::createFromMethodName('Rcfm::stat');
echo $rs->getName(), "\n";
echo $rs->isStatic() ? "static\n" : "instance\n";

$r2 = new ReflectionMethod(Rcfm::class, 'inst');
echo $r2->getName() === $r->getName() ? "ctor-match\n" : "ctor-mismatch\n";

try {
    ReflectionMethod::createFromMethodName('Rcfm::nope');
    echo "no_ex\n";
} catch (ReflectionException $e) {
    echo "method ", $e->getMessage(), "\n";
}

try {
    ReflectionMethod::createFromMethodName('NoSuchRcfm_xyz::m');
    echo "no_ex\n";
} catch (ReflectionException $e) {
    echo "class ", $e->getMessage(), "\n";
}

try {
    ReflectionMethod::createFromMethodName('invalid');
    echo "no_ex\n";
} catch (ReflectionException $e) {
    echo "invalid ", $e->getMessage(), "\n";
}
--EXPECT--
createFromMethodName=true
inst
public
stat
static
ctor-match
method Method Rcfm::nope() does not exist
class Class "NoSuchRcfm_xyz" does not exist
invalid ReflectionMethod::createFromMethodName(): Argument #1 ($method) must be a valid method name
