<?php
/**
 * Bool/null dim writes must persist in the hashtable (#36380).
 *
 * Regression: AssignDispatch broke indirection for boolean/null RHS even on
 * real FETCH_DIM_W lvalues (#36398 guard), so `$a['continuable'] = true` left
 * a null HT cell and isset() returned false — Parsedown block continuation
 * then failed (code blocks / lists / setext).
 *
 * php-src: Zend/zend_vm_def.h ZEND_ASSIGN + zend_assign_to_variable_dim /
 * Zend/zend_hash.c zend_hash_update.
 */
$a = [];
$a['continuable'] = true;
$a['identified'] = true;
$a['x'] = false;
$a['n'] = null;

echo isset($a['continuable']) ? "cont_isset=1\n" : "cont_isset=0\n";
echo true === $a['continuable'] ? "cont_val=1\n" : "cont_val=0\n";
echo isset($a['identified']) ? "id_isset=1\n" : "id_isset=0\n";
echo array_key_exists('x', $a) && false === $a['x'] ? "x_false=1\n" : "x_false=0\n";
echo array_key_exists('n', $a) && null === $a['n'] ? "n_null=1\n" : "n_null=0\n";

// Indented code-block continuation (Parsedown blockCodeContinue path).
require dirname(__DIR__, 2) . '/test/apps/erusev-parsedown/pinned/Parsedown.php';
$p = new Parsedown();
$out = $p->text("    line1\n    line2\n");
echo (false !== strpos($out, "line1\nline2")) ? "code_continue=1\n" : "code_continue=0\n";
