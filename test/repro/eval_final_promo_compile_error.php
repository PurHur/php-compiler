<?php
/**
 * #28481 — eval(public final promoted ctor prop) must throw catchable CompileError
 * under PROFILE=8.4 (php-src Zend/zend_compile.c), not uncaught PHP Fatal.
 *
 *   PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/eval_final_promo_compile_error.php
 */
try {
    eval('class C { public function __construct(public final int $x) {} }');
    echo "accepted", PHP_EOL;
} catch (CompileError $e) {
    echo 'CompileError:', $e->getMessage(), PHP_EOL;
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), PHP_EOL;
}
echo "after", PHP_EOL;
