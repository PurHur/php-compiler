<?php
/**
 * #28393 — issue-body repro A (php-src-strict, PROFILE≥8.4).
 *
 * Expect: wrote / isFinal=1 (post-construct write allowed; Reflection final flag set).
 *
 * Run:
 *   PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_28393_final_plain_isfinal.php
 *   PHP_COMPILER_PROFILE=8.4 php bin/jit.php test/repro/issue_28393_final_plain_isfinal.php
 *   PHP_COMPILER_PROFILE=8.4 ./phpc build -o /tmp/i28393a test/repro/issue_28393_final_plain_isfinal.php && /tmp/i28393a
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
