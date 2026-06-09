--TEST--
Stdlib: ReflectionEnum::getCases() excludes user consts — only enum cases (php_reflection.c, #6626)
--FILE--
<?php
enum E: string {
    case A = 'a';
    public const X = 'meta';
}
$re = new ReflectionEnum(E::class);
$names = [];
foreach ($re->getCases() as $c) {
    $names[] = $c->getName();
}
echo count($names), "\n";
echo implode(',', $names), "\n";
try {
    $re->getCase('X');
    echo "no throw\n";
} catch (ReflectionException $e) {
    echo 'getCase ', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'getCase wrong ', get_class($e), "\n";
}
--EXPECT--
1
A
getCase Case E::X does not exist
