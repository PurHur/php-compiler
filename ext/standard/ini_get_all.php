<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** ini_get_all() — INI directive introspection (ext/standard/ini.c, #3205). */
final class ini_get_all extends Internal
{
    public function __construct()
    {
        parent::__construct('ini_get_all');
    }

    public function execute(Frame $frame): void
    {
        if (\count($frame->calledArgs) > 2) {
            throw new \ArgumentCountError(
                'ini_get_all() expects at most 2 arguments, '.\count($frame->calledArgs).' given'
            );
        }
        if (null === $frame->vmContext || null === $frame->returnVar) {
            return;
        }

        $extension = null;
        $details = true;
        if (\count($frame->calledArgs) >= 1) {
            $extension = VmString::coerceTypedNullableStringBuiltinArg(
                $frame->calledArgs[0],
                'ini_get_all',
                0,
                'extension'
            );
        }
        if (2 === \count($frame->calledArgs)) {
            $arg1 = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $arg1->type) {
                throw new \LogicException('ini_get_all() details flag must be a boolean in this compiler build');
            }
            $details = $arg1->toBool();
        }

        $result = VmIni::getAll($frame->vmContext, $extension, $details, $frame);
        if (false === $result) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->array($result);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitIniGetAll::invoke($context, ...$args);
    }
}
