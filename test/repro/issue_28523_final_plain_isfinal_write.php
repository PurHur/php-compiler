<?php
/**
 * #28523 — PROFILE≥8.4 final plain property: isFinal + write (php-src-strict).
 *
 * php-src: Zend/zend_compile.c (ZEND_ACC_FINAL), ext/reflection/php_reflection.c
 * (isFinal), Zend/zend_object_handlers.c — plain final does NOT reject writes
 * (inheritance-only; #23683). Verified php:8.4-cli: WRITE_OK.
 *
 * Expect:
 *   isFinal=1
 *   WRITE_OK
 *
 * Run:
 *   PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_28523_final_plain_isfinal_write.php
 *   PHP_COMPILER_PROFILE=8.4 php bin/jit.php test/repro/issue_28523_final_plain_isfinal_write.php
 */
class A { public final int $x = 1; }
$r = new ReflectionProperty(A::class, "x");
echo "isFinal=", $r->isFinal() ? "1" : "0", "\n";
$a = new A();
try { $a->x = 9; echo "WRITE_OK\n"; } catch (Throwable $e) { echo "WRITE_ERR\n"; }
