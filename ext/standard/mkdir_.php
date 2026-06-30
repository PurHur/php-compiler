<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\NamedOptionalCallArgs;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
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
        if (!isset($frame->calledArgs[0])) {
            throw new \ArgumentCountError('mkdir(): Argument #1 ($directory) not passed');
        }
        InternalStrictArg::rejectNullString($frame->calledArgs[0], 'mkdir', 'directory', 0, $frame);
        $path = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'mkdir', 0, 'directory');
        $mode = 0777;
        if (isset($frame->calledArgs[1])) {
            $mode = VmFilestatArg::parseFileModeArgForFrame($frame, 1, 'mkdir', 'permissions');
        }
        $recursive = false;
        if (isset($frame->calledArgs[2])) {
            $recVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $recVar->type) {
                throw new \LogicException('mkdir() recursive flag must be a boolean in this compiler build');
            }
            $recursive = $recVar->toBool();
        }
        $alreadyDir = VmStatPath::isDir($path);
        $ok = VmFs::mkdir($path, $mode, $recursive);
        if (!$ok) {
            if ($alreadyDir) {
                VmFilestatFailure::warnMkdirFileExists($frame);
            } else {
                VmFilestatFailure::warnNoSuchFile($frame, 'mkdir');
            }
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
        if (isset($args[1]) && !NamedOptionalCallArgs::isOmittedOptional($args[1])) {
            $mode = JitFilestatArg::lowerFileMode($context, $args[1], 'mkdir', 1, 'permissions');
        }
        $recursive = $context->constantFromBool(false);
        if (isset($args[2]) && !NamedOptionalCallArgs::isOmittedOptional($args[2])) {
            $recursive = $this->jitBool($context, $args[2], 'mkdir() argument #3');
        }

        return JitMkdir::invoke($context, $path, $mode, $recursive);
    }
}
