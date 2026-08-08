<?php
/**
 * #28956 — PROFILE≥8.4 final plain property: isFinal + write (php-src-strict).
 *
 * Re-#28816 / #28627. Exact issue-body shape: `final public string $x` then
 * ReflectionProperty::isFinal() and a post-construct write (inheritance-only —
 * Zend allows writes on non-readonly final props).
 *
 * php-src: Zend/zend_compile.c (ZEND_ACC_FINAL), ext/reflection/php_reflection.c
 * (isFinal), Zend/zend_object_handlers.c
 *
 * Expect:
 *   isFinal=1
 *   wrote=c
 *
 * Run:
 *   PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_28956_final_plain_isfinal_write.php
 *   PHP_COMPILER_PROFILE=8.4 php bin/jit.php test/repro/issue_28956_final_plain_isfinal_write.php
 */
class A { final public string $x = "a"; }
$rp = new ReflectionProperty(A::class, "x");
echo "isFinal=", (int) $rp->isFinal(), "\n";
$a = new A();
$a->x = "c";
echo "wrote=", $a->x, "\n";
