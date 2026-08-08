<?php
/**
 * Repro #29130 — constant()/defined() must honor private/protected class const visibility.
 */
class A {
    private const X = 1;
    protected const Y = 2;
    public const Z = 3;
}
foreach (['A::X', 'A::Y', 'A::Z'] as $n) {
    try {
        $v = constant($n);
        echo $n, '=', $v, "\n";
    } catch (Throwable $e) {
        echo $n, '=', get_class($e), ':', $e->getMessage(), "\n";
    }
}
echo 'defX=', defined('A::X') ? '1' : '0', "\n";
echo 'defY=', defined('A::Y') ? '1' : '0', "\n";
echo 'defZ=', defined('A::Z') ? '1' : '0', "\n";
