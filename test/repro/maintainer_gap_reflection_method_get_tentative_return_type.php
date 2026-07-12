<?php

declare(strict_types=1);

$rm = new ReflectionMethod('DateTime', 'format');
echo method_exists($rm, 'getTentativeReturnType') ? "method_yes\n" : "method_no\n";
echo 'name=' . ($rm->getTentativeReturnType()?->getName() ?? 'null') . "\n";
echo $rm->hasTentativeReturnType() ? "has_yes\n" : "has_no\n";

class Typed {
    public function typed(): int { return 1; }
}

$user = new ReflectionMethod(Typed::class, 'typed');
echo $user->hasTentativeReturnType() ? "user_has_yes\n" : "user_has_no\n";
echo null === $user->getTentativeReturnType() ? "user_get_null\n" : "user_get_set\n";

echo "ok name=string\n";
