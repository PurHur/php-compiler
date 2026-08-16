<?php
/**
 * #23400 — strcspn/strspn/strpbrk Reflection names + Zend-style named args
 * php-src: ext/standard/basic_functions.stub.php
 */
foreach (['strcspn', 'strspn', 'strpbrk'] as $fn) {
    $rf = new ReflectionFunction($fn);
    echo $fn, '=';
    foreach ($rf->getParameters() as $p) {
        echo $p->getName(), ',';
    }
    echo "\n";
}
try {
    echo strcspn(string: 'abc', characters: 'c'), "\n";
} catch (Throwable $e) {
    echo 'strcspn ERR=', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    echo strspn(string: 'abc', characters: 'ab'), "\n";
} catch (Throwable $e) {
    echo 'strspn ERR=', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    var_export(strpbrk(string: 'abc', characters: 'b'));
    echo "\n";
} catch (Throwable $e) {
    echo 'strpbrk ERR=', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    strcspn(str: 'abc', mask: 'c');
    echo "legacy strcspn ok\n";
} catch (Throwable $e) {
    echo 'legacy strcspn ERR=', get_class($e), ': ', $e->getMessage(), "\n";
}
