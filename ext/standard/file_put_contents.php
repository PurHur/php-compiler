<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** file_put_contents() — string data; flags FILE_APPEND (8) and LOCK_EX (2) in JIT (#194, #4275). */
final class file_put_contents extends Internal
{
    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 4) {
            throw new \ArgumentCountError(
                'file_put_contents() expects at least 2 arguments, '.\max(0, $argc - 2).' given'
            );
        }
        $dataVar = $frame->calledArgs[1]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        $path = VmFilestatArg::coerceFilenameArg($frame->calledArgs[0], 'file_put_contents');
        $flags = 0;
        if (isset($frame->calledArgs[2])) {
            $flagsVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $flagsVar->type) {
                throw new \LogicException('file_put_contents() flags must be an integer in this compiler build');
            }
            $flags = $flagsVar->toInt();
        }
        if (isset($frame->calledArgs[3])) {
            $contextVar = $frame->calledArgs[3]->resolveIndirect();
            if (Variable::TYPE_NULL !== $contextVar->type) {
                VmStreamContext::requireRepresentation($contextVar, 'file_put_contents', 4);
            }
        }
        $data = self::coerceData($dataVar);
        $written = VmFs::filePutContents($path, $data, $flags);
        if (false === $written) {
            VmStreamOpenFailure::warnFailedToOpen($frame, 'file_put_contents', $path);
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($written);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 4) {
            throw new \ArgumentCountError(
                'file_put_contents() expects at least 2 arguments, '.\max(0, $argc - 2).' given'
            );
        }
        $flags = 0;
        if ($argc >= 3) {
            if (JITVariable::TYPE_NATIVE_LONG !== $args[2]->type) {
                throw new \LogicException('file_put_contents() flags must be an integer in this compiler build');
            }
            $flagsVal = $args[2]->compileTimeLong ?? null;
            if (null !== $flagsVal) {
                self::assertSupportedFlags($flagsVal);
            }
            $flags = $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[2], 'file_put_contents() flags'),
                $context->getTypeFromString('int64')
            );
        } else {
            $flags = $context->getTypeFromString('int64')->constInt(0, false);
        }

        return JitFilePutContents::invoke(
            $context,
            JitFilestatArg::lowerFilename($context, $args[0], 'file_put_contents'),
            $context->helper->loadValue($args[1]),
            $flags
        );
    }

    /** Zend bitmask: FILE_APPEND (8) | LOCK_EX (2) only (#4275). */
    private static function assertSupportedFlags(int $flags): void
    {
        $known = 2 | 8;
        if (0 !== ($flags & ~$known)) {
            throw new \LogicException(
                'file_put_contents() flags must be 0, LOCK_EX (2), FILE_APPEND (8), or their combination in this compiler build'
            );
        }
    }

    /**
     * @return string|list<string>
     */
    private static function coerceData(Variable $var)
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_STRING === $var->type) {
            return $var->toString();
        }
        if (Variable::TYPE_ARRAY === $var->type) {
            $lines = [];
            foreach ($var->toArray()->iterate(true) as $entry) {
                $lines[] = $entry->toString();
            }

            return $lines;
        }
        throw new \LogicException('file_put_contents() data must be a string or array in this compiler build');
    }
}
