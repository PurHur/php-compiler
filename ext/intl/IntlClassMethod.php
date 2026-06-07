<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * Shared VM/JIT wiring for intl class method skeleton stubs (issue #5925).
 */
abstract class IntlClassMethod extends VmClassMethod
{
    abstract protected function skeletonIssue(): int;

    abstract protected function declaringClass(): string;

    public function execute(Frame $frame): void
    {
        $issue = $this->skeletonIssue();
        $class = $this->declaringClass();
        $method = $this->getName();
        throw new \Error("{$class}::{$method}() is not implemented in this compiler build (issue #{$issue})");
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $issue = $this->skeletonIssue();
        $class = $this->declaringClass();
        $method = $this->getName();
        throw new \Error("{$class}::{$method}() is not implemented for JIT in this compiler build (issue #{$issue})");
    }
}
