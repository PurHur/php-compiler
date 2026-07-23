<?php
/**
 * Issue #22423 — illegal Closure::bindTo must E_WARNING + null (Zend), not LogicException.
 * php-src: Zend/zend_closures.c zend_closure_bind_to
 */
declare(strict_types=1);

class A {
    private $x = 7;
    public function get() {
        return function () {
            return $this->x;
        };
    }
}

$f = (new A)->get();
set_error_handler(function ($n, $s) {
    echo "WARN $s\n";

    return true;
});
$g = $f->bindTo(null, A::class);
var_export($g);
echo "\n";

$s = static function () {
    return 1;
};
$h = $s->bindTo(new stdClass);
var_export($h);
echo "\n";
