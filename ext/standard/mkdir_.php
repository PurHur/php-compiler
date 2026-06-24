<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** mkdir() — VM via VmFs; JIT/AOT via __compiler_mkdir (libc mkdir(2), recursive in C). */
final class mkdir_ extends Internal
{
    public function __construct()
    {
        parent::__construct('mkdir');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('mkdir() requires one to three arguments in this compiler build');
        }
        $path = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'mkdir', 0, 'directory');
        $mode = 0777;
        if ($argc >= 2) {
            $modeVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $modeVar->type) {
                throw new \LogicException('mkdir() mode must be an integer in this compiler build');
            }
            $mode = $modeVar->toInt();
        }
        $recursive = false;
        if (3 === $argc) {
            $recVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $recVar->type) {
                throw new \LogicException('mkdir() recursive flag must be a boolean in this compiler build');
            }
            $recursive = $recVar->toBool();
        }
        $alreadyDir = VmStatPath::isDir($path);
        $ok = VmFs::mkdir($path, $mode, $recursive);
        if (!$ok && $alreadyDir) {
            VmFilestatFailure::warnMkdirFileExists($frame);
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('mkdir() requires one to three arguments in this compiler build');
        }
        $path = JitStringBuiltinArg::lower($context, $args[0], 'mkdir', 0, 'directory');
        $i64 = $context->getTypeFromString('int64');
        $mode = $i64->constInt(0777, false);
        if ($argc >= 2) {
            $mode = JitLongArg::lower($context, $args[1], 'mkdir() argument #2');
        }
        $recursive = $context->constantFromBool(false);
        if (3 === $argc) {
            $recursive = $this->jitBool($context, $args[2], 'mkdir() argument #3');
        }

        return JitMkdir::invoke($context, $path, $mode, $recursive);
    }
}
