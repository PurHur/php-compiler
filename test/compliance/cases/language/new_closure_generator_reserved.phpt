--TEST--
Language: new Closure()/new Generator() — reserved internal classes Error (#13324)
--FILE--
<?php
foreach (['Closure', 'Generator'] as $class) {
    try {
        new $class();
        echo "fail: new {$class}() succeeded\n";
    } catch (Error $e) {
        echo $class, ':', $e::class, ':', $e->getMessage(), "\n";
    }
}
--EXPECT--
Closure:Error:Instantiation of class Closure is not allowed
Generator:Error:The "Generator" class is reserved for internal use and cannot be manually instantiated
