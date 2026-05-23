<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** fgetcsv() — VM via VmFs; JIT/AOT via __compiler_fgetcsv (issue #1192). */
final class fgetcsv extends Internal
{
    public function __construct()
    {
        parent::__construct('fgetcsv');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 5) {
            throw new \LogicException('fgetcsv() requires one to five arguments in this compiler build');
        }
        $handleVar = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_INTEGER !== $handleVar->type) {
            throw new \LogicException('fgetcsv() handle must be an integer in this compiler build');
        }
        $length = null;
        if ($argc >= 2) {
            $lenVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $lenVar->type) {
                throw new \LogicException('fgetcsv() length must be an integer in this compiler build');
            }
            $length = $lenVar->toInt();
        }
        $separator = ',';
        $enclosure = '"';
        $escape = '\\';
        if ($argc >= 3) {
            $separator = VmReflection::stringArg($frame->calledArgs[2], 'fgetcsv() separator');
        }
        if ($argc >= 4) {
            $enclosure = VmReflection::stringArg($frame->calledArgs[3], 'fgetcsv() enclosure');
        }
        if ($argc >= 5) {
            $escape = VmReflection::stringArg($frame->calledArgs[4], 'fgetcsv() escape');
        }
        $row = VmFs::fgetcsv($handleVar->toInt(), $length, $separator, $enclosure, $escape);
        if (false === $row) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->array(VmFs::stringListToArray($row));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 5) {
            throw new \LogicException('fgetcsv() requires one to five arguments in this compiler build');
        }
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $handle = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[0], 'fgetcsv() handle'),
            $i64
        );
        $length = $i64->constInt(-1, true);
        if ($argc >= 2) {
            $length = $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[1], 'fgetcsv() length'),
                $i64
            );
        }
        $separator = $strPtr->constNull();
        $enclosure = $strPtr->constNull();
        $escape = $strPtr->constNull();
        if ($argc >= 3) {
            $separator = JitStringArg::lower($context, $args[2], 'fgetcsv() separator');
        }
        if ($argc >= 4) {
            $enclosure = JitStringArg::lower($context, $args[3], 'fgetcsv() enclosure');
        }
        if ($argc >= 5) {
            $escape = JitStringArg::lower($context, $args[4], 'fgetcsv() escape');
        }

        return JitFgetcsv::invoke($context, $handle, $length, $separator, $enclosure, $escape);
    }
}
