<?php
/**
 * Repro #24927 — string dim in class const `"ab"[0]` (Zend/zend_compile.c const expr).
 */
class C
{
    public const X = 'ab'[0];
}
echo C::X, "\n";
