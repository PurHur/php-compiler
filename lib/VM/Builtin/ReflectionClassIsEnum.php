<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmReflection;
use PHPCompiler\Frame;
use PHPCompiler\JIT\Builtin\ReflectionSetup;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\ReflectionSupport;
use PHPLLVM\Value;

/** ReflectionClass::isEnum() — VM (#5666, ext/reflection/php_reflection.c). */
final class ReflectionClassIsEnum extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('isEnum');
    }

    public function execute(Frame $frame): void
    {
        // php-src: zim_ReflectionClass_isEnum — ZEND_PARSE_PARAMETERS (0 args) (#31126)
        $this->requireExactUserArgCount($frame, 'ReflectionClass::isEnum', 0);
        $receiver = ReflectionSupport::requireReflectionClass($frame, $frame->calledArgs[0]);
        $ctx = VmReflection::requireContext($frame);
        $className = ReflectionSupport::classNameFromReflection($receiver);
        $entry = VmReflection::resolveClassEntry($ctx, $className);
        if (null === $entry) {
            throw new \LogicException('ReflectionClass refers to unknown class in this compiler build');
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($entry->isEnum);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!self::requireExactJitUserArgCount($context, $args, 'ReflectionClass::isEnum', 0)) {
            return self::jitArgcDummyReturn($context);
        }

        return ReflectionSetup::emitKindQuery($context, $args, 'isEnum');
    }
}
