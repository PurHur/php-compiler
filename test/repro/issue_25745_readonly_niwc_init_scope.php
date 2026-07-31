<?php
/**
 * Issue #25745 — readonly property first-init after NIWC must reject global scope
 * (Zend/zend_readonly.c). Never print SET_OK.
 *
 *   php test/repro/issue_25745_readonly_niwc_init_scope.php
 *   php bin/vm.php test/repro/issue_25745_readonly_niwc_init_scope.php
 *   php bin/jit.php test/repro/issue_25745_readonly_niwc_init_scope.php
 */
class R
{
    public readonly int $x;

    public function __construct(int $x)
    {
        $this->x = $x;
    }

    public function init(int $x): void
    {
        $this->x = $x;
    }
}

$o = (new ReflectionClass(R::class))->newInstanceWithoutConstructor();
try {
    $o->x = 1;
    echo "SET_OK\n";
} catch (Throwable $e) {
    echo get_class($e) . ':' . $e->getMessage() . "\n";
}
try {
    echo $o->x . "\n";
} catch (Throwable $e) {
    echo 'read=' . get_class($e) . ':' . $e->getMessage() . "\n";
}

$o2 = (new ReflectionClass(R::class))->newInstanceWithoutConstructor();
try {
    $o2->init(2);
    echo 'method_ok:' . $o2->x . "\n";
} catch (Throwable $e) {
    echo 'method=' . get_class($e) . ':' . $e->getMessage() . "\n";
}
