<?php

declare(strict_types=1);

/**
 * #34713 — nullsafe method on null short-circuits to NULL (ZEND_NULLSAFE_METHODCALL).
 *
 * @see php-src Zend/zend_vm_def.h ZEND_NULLSAFE_METHODCALL
 */
$o = null;
var_dump($o?->m());
