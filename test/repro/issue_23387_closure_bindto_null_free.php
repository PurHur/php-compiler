<?php
/**
 * Issue #23387 — Closure::bindTo(null) on free using-$this closure (Zend unbound Closure).
 * php-src: Zend/zend_closures.c zend_closure_bind_to (!Z_ISUNDEF(this_ptr) && USES_THIS)
 */
declare(strict_types=1);

$f = function () {
    return isset($this) ? 'yes' : 'no';
};
$b = $f->bindTo(null);
var_export(is_object($b));
echo "\n";
if ($b) {
    echo $b(), "\n";
} else {
    echo "null_result\n";
}
