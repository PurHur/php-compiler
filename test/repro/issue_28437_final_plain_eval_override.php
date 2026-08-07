<?php
/**
 * #28437 — AOT/VM/JIT: eval() child must not override outer-unit final plain property.
 *
 * Expect under PHP_COMPILER_PROFILE=8.4:
 *   isFinal=1
 *   then Fatal: Cannot override final property A::$x (never redef_ok)
 *
 * AOT previously emitFalse'd decl eval and printed redef_ok (#22988 gap).
 *
 * Run:
 *   PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_28437_final_plain_eval_override.php
 *   PHP_COMPILER_PROFILE=8.4 php bin/jit.php test/repro/issue_28437_final_plain_eval_override.php
 *   PHP_COMPILER_PROFILE=8.4 php bin/compile.php -o /tmp/i28437 test/repro/issue_28437_final_plain_eval_override.php
 */
class A
{
    public final int $x = 1;
}
$r = new ReflectionProperty(A::class, 'x');
echo 'isFinal=', $r->isFinal() ? '1' : '0', "\n";
try {
    eval('class B extends A { public int $x = 2; }');
    echo "redef_ok\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
