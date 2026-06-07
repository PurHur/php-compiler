<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Sibling class constant referenced before its value is materialized (#7382).
 */
final class ClassConstForwardReferenceException extends \LogicException
{
    public function __construct(string $className, string $constNameLc)
    {
        parent::__construct("Forward reference to class constant {$className}::{$constNameLc}");
    }
}
