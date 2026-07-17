<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\VM\ReflectionSupport;

/**
 * ReflectionEnumUnitCase::getDocComment() — VM (#19785, ext/reflection/php_reflection.c).
 *
 * Enum case doc comments are not yet stored on ClassEntry; match Zend's false when absent.
 */
final class ReflectionEnumUnitCaseGetDocComment extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('getDocComment');
    }

    public function execute(Frame $frame): void
    {
        ReflectionSupport::requireReflectionEnumCase($frame, $frame->calledArgs[0]);
        ReflectionSupport::returnDocComment($frame->returnVar, null);
    }
}
