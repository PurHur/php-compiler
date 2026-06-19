--TEST--
Stdlib: class_has_method() — invalid allow_string TypeError (#9989, php-src-strict)
--FILE--
<?php
declare(strict_types=1);

class C { public function m(): void {} }

try {
    class_has_method(C::class, 'm', true, 'bogus');
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
class_has_method(): Argument #4 ($allow_string) must be of type bool, string given
