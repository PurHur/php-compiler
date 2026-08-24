<?php
/**
 * #26289 — get_error_handler()/get_exception_handler() Reflection return ?callable (PROFILE=8.5).
 * php-src: ext/standard/basic_functions.stub.php
 */
foreach (['get_error_handler', 'get_exception_handler'] as $fn) {
    $rt = (new ReflectionFunction($fn))->getReturnType();
    if (null === $rt) {
        fwrite(STDERR, "fail: $fn has no return type\n");
        exit(1);
    }
    echo $fn, ' ret=', (string) $rt, ' null=', $rt->allowsNull() ? 'yes' : 'no', "\n";
}
