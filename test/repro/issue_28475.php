<?php
/**
 * Repro #28475 — function/class/interface/trait/enum_exists excess/missing argc
 * must throw ArgumentCountError (not LogicException).
 * php-src: Zend/zend_builtin_functions.stub.php
 */
foreach (['function_exists', 'class_exists', 'interface_exists', 'trait_exists', 'enum_exists'] as $f) {
    try {
        $f();
        echo "$f/0:ok\n";
    } catch (Throwable $e) {
        echo "$f/0:", get_class($e), ':', $e->getMessage(), "\n";
    }
}
try {
    function_exists('strlen', 'x');
    echo "function_exists/2:ok\n";
} catch (Throwable $e) {
    echo 'function_exists/2:', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    class_exists('stdClass', true, 'x');
    echo "class_exists/3:ok\n";
} catch (Throwable $e) {
    echo 'class_exists/3:', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    interface_exists('Traversable', true, 'x');
    echo "interface_exists/3:ok\n";
} catch (Throwable $e) {
    echo 'interface_exists/3:', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    trait_exists('NoSuchTrait', true, 'x');
    echo "trait_exists/3:ok\n";
} catch (Throwable $e) {
    echo 'trait_exists/3:', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    enum_exists('NoSuchEnum', true, 'x');
    echo "enum_exists/3:ok\n";
} catch (Throwable $e) {
    echo 'enum_exists/3:', get_class($e), ':', $e->getMessage(), "\n";
}
echo 'function_exists_ok:', function_exists('strlen') ? '1' : '0', "\n";
echo 'class_exists_ok:', class_exists('stdClass') ? '1' : '0', "\n";
echo 'interface_exists_ok:', interface_exists('Traversable') ? '1' : '0', "\n";
