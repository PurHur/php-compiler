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

/** crc32() — CRC32B (IEEE), signed 32-bit int (VM + JIT/AOT via __compiler_crc32). */
final class crc32 extends Internal
{
    public function __construct()
    {
        parent::__construct('crc32');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('crc32() requires one or two arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $data = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING !== $data->type) {
            throw new \LogicException('crc32() requires a string in this compiler build');
        }
        $seed = 0;
        if (2 === $argc) {
            $seedArg = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $seedArg->type) {
                throw new \LogicException('crc32() seed must be an integer in this compiler build');
            }
            $seed = $seedArg->toInt();
        }
        $frame->returnVar->int(VmCrc32::crc32($data->toString(), $seed));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 1 || \count($args) > 2) {
            throw new \LogicException('crc32() requires one or two arguments in this compiler build');
        }
        $str = JitStringArg::lower($context, $args[0], 'crc32() string');
        $seed = $context->getTypeFromString('int64')->constInt(0, false);
        if (isset($args[1])) {
            $seed = JitLongArg::lower($context, $args[1], 'crc32() seed');
        }

        return JitCrc32::invoke($context, $str, $seed);
    }
}
