<?php

declare(strict_types=1);

/**
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * is_resource() — stream handle detection (ext/standard/basic_functions.c parity, #3519).
 *
 * VM: {@see Variable::streamHandle()} tags fopen() results so plain integers stay false.
 * JIT/AOT: {@see __compiler_is_resource} checks the native stream handle table.
 */
final class is_resource_ extends Internal
{
    public function __construct()
    {
        parent::__construct('is_resource');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('is_resource() requires exactly one argument');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(self::isResource($v));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('is_resource() requires exactly one argument');
        }
        \PHPCompiler\JIT\Builtin\StringDir::ensureLinked($context);
        if (JITVariable::TYPE_NULL === $args[0]->type) {
            return $context->constantFromBool(false);
        }

        return JitIsResource::invoke(
            $context,
            $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[0], 'is_resource() argument #1'),
                $context->getTypeFromString('int64')
            )
        );
    }

    public static function isResource(Variable $v): bool
    {
        if ($v->isStreamResource()) {
            return VmFs::isValidHandle($v->toInt());
        }
        if ($v->isDirResource()) {
            return VmDir::isValidHandle($v->toInt());
        }

        return false;
    }
}
