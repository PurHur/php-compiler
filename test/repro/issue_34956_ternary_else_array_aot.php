<?php
/**
 * #34956 — AOT ternary else-arm array literal must match Zend (leftover of #34944).
 *
 * php-src: Zend/zend_ast.c ZEND_AST_CONDITIONAL
 */
class C
{
    public $x = 'hi';
}
$o = new C();
$f = false;
var_export($f ? [$o->x] : ['x']);
echo "\n";
var_export($o ? [$o->x] : null);
echo "\n";
var_export($f ? [$o->x] : null);
echo "\n";
