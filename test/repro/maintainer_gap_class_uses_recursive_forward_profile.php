<?php

declare(strict_types=1);

if (!function_exists('class_uses_recursive')) {
    echo "fail: class_uses_recursive not registered under forward profile\n";
    exit(1);
}

trait A {}
trait B {
    use A;
}
class C {
    use B;
}

$recursive = class_uses_recursive(C::class);
if (!isset($recursive['A'], $recursive['B'])) {
    echo "fail: missing nested traits\n";
    exit(1);
}

echo "ok\n";
