<?php
/**
 * #28078 — final plain properties under PROFILE=8.4 with try/catch write (php-src-strict).
 *
 * Zend 8.4+: post-construct write OK; ReflectionProperty::isFinal() true; child
 * redeclaration is compile fatal. AOT previously exited after a successful
 * `$obj->prop = …` inside try because try-body sealing used the original LLVM
 * entry (already terminated by DynamicObjectReadonlyGuard) and sealFunction
 * emitted `ret void` on the real continuation.
 *
 * php-src: Zend/zend_compile.c, Zend/zend_inheritance.c, ext/reflection/php_reflection.c
 *
 * Run:
 *   PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/maintainer_gap_final_plain_property_try_84.php
 *   PHP_COMPILER_PROFILE=8.4 php bin/jit.php test/repro/maintainer_gap_final_plain_property_try_84.php
 *   PHP_COMPILER_PROFILE=8.4 ./phpc build -o /tmp/f84try test/repro/maintainer_gap_final_plain_property_try_84.php && /tmp/f84try
 */
class A
{
    final public string $x;

    public function __construct(string $x)
    {
        $this->x = $x;
    }
}

$a = new A('a');
try {
    $a->x = 'b';
    echo "wrote\n";
} catch (Throwable $e) {
    echo "write_err\n";
}
$r = new ReflectionProperty(A::class, 'x');
echo 'isFinal=', $r->isFinal() ? '1' : '0', "\n";
