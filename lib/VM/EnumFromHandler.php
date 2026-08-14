<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * VM handler for BackedEnum::from() and ::tryFrom() (#3114).
 */
final class EnumFromHandler extends Internal
{
    public function __construct(
        private ClassEntry $enum,
        private bool $try,
    ) {
        parent::__construct($enum->name.'::'.($try ? 'tryFrom' : 'from'));
    }

    public function execute(Frame $frame): void
    {
        $enum = EnumSupport::resolveRuntimeEnumClass($frame->vmContext, $this->enum);
        EnumSupport::ensureBackedEnumValuesUnique($enum);
        // php-src: Zend/zend_enum.c — zend_enum_from_func / zend_enum_try_from_func (#30864)
        // Static: calledArgs are user args only (no $this).
        $argc = \count($frame->calledArgs);
        $method = $this->try ? 'tryFrom' : 'from';
        $function = $enum->name.'::'.$method;
        if (1 !== $argc) {
            throw new \ArgumentCountError(
                Builtin\VmClassMethod::exactUserArgCountMessage($function, 1, $argc)
            );
        }
        $arg = $frame->calledArgs[0];
        BackedEnum::assertStrictCallerBackingArg(
            $enum,
            $arg,
            $frame,
            $method
        );
        $match = BackedEnum::caseForValue($enum, $arg, $frame->vmContext, $frame, $method);
        if (null === $match) {
            if (!$this->try) {
                throw new \ValueError(BackedEnum::valueErrorMessage($enum, $arg));
            }
            if (null !== $frame->returnVar) {
                $frame->returnVar->null();
            }

            return;
        }
        if (null !== $frame->returnVar) {
            $canonical = BackedEnum::canonicalCaseVariable($enum, $match->caseName);
            if (null !== $canonical && EnumCaseSupport::isEnumCaseVariable($canonical)) {
                $frame->returnVar->copyFrom($canonical);
            } else {
                $frame->returnVar->enumCase($match);
            }
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->getName().'() is not supported in JIT in this compiler build');
    }
}
