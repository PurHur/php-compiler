<?php

declare(strict_types=1);

var_dump(class_parents('stdClass', autoload: false));
var_dump(class_implements('Iterator', autoload: false));
var_dump(class_uses('stdClass', autoload: false));

if (function_exists('class_uses_recursive')) {
    trait T9172 {}
    class C9172 {
        use T9172;
    }
    var_dump(class_uses_recursive(C9172::class, autoload: false));
}

echo "ok\n";
