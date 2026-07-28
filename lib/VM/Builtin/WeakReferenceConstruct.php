<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;

/** Disallow direct instantiation (issue #1366 / #24432 — Zend/zend_weakrefs.c). */
final class WeakReferenceConstruct extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('__construct');
    }

    public function execute(Frame $frame): void
    {
        // php-src: Error, not LogicException — "Direct instantiation of WeakReference is not allowed…"
        throw new \Error(
            'Direct instantiation of WeakReference is not allowed, use WeakReference::create instead'
        );
    }
}
