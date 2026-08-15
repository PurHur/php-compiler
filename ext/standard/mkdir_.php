<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NamedOptionalCallArgs;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * mkdir() — VM via VmFs; JIT/AOT via MkdirJitHelper PHP (#15586, #23453).
 *
 * php-src: ext/standard/filestat.c — PHP_FUNCTION(mkdir) / php_stream_mkdir with optional context.
 * Stream $context is accepted and type-checked; wrapper contexts that change mkdir semantics
 * are not yet applied (same accept/ignore shape as unlink/copy/rename).
 */
final class mkdir_ extends Internal
{
    public function __construct()
    {
        parent::__construct('mkdir');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \ArgumentCountError(
                'mkdir() expects at least 1 argument, '.$argc.' given'
            );
        }
        if ($argc > 4) {
            throw new \ArgumentCountError(
                'mkdir() expects at most 4 arguments, '.$argc.' given'
            );
        }
        if (!isset($frame->calledArgs[0])) {
            throw new \ArgumentCountError('mkdir(): Argument #1 ($directory) not passed');
        }
        if (isset($frame->calledArgs[3])) {
            VmStreamContext::validateOptionalContextArg($frame->calledArgs[3], 'mkdir', 4);
        }
        $path = VmFilestatArg::coerceFilenameArg($frame->calledArgs[0], 'mkdir', 0, 'directory', $frame);
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
            VmFilestatFailure::warnMkdirFailed($frame, $path, $recursive, $alreadyDir);
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1) {
            throw new \ArgumentCountError(
                'mkdir() expects at least 1 argument, '.$argc.' given'
            );
        }
        if ($argc > 4) {
            throw new \ArgumentCountError(
                'mkdir() expects at most 4 arguments, '.$argc.' given'
            );
        }
        if (isset($args[3]) && !NamedOptionalCallArgs::isOmittedOptional($args[3])) {
            JitStreamContextOptionalArg::validate($context, $args[3], 'mkdir', 4);
        }
        $path = JitFilestatArg::lowerFilename($context, $args[0], 'mkdir', 0, 'directory');
        $i64 = $context->getTypeFromString('int64');
        $mode = $i64->constInt(0777, false);
        if (isset($args[1]) && !NamedOptionalCallArgs::isOmittedOptional($args[1])) {
            // Early return after compile-time null TypeError — no mkdir invoke after abort
            // (AOT module verify; peer getprotobynumber #30283 / dirname #31210).
            if ($context->callerStrictTypes
                && (JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false))) {
                JitFilestatArg::lowerFileMode($context, $args[1], 'mkdir', 1, 'permissions');
                BasicBlockHelper::ensureOpenInsertBlock($context, 'mkdir_null_permissions_te_cont');

                return $context->constantFromBool(false);
            }
            $mode = JitFilestatArg::lowerFileMode($context, $args[1], 'mkdir', 1, 'permissions');
        }
        $recursive = $context->constantFromBool(false);
        if (isset($args[2]) && !NamedOptionalCallArgs::isOmittedOptional($args[2])) {
            $recursive = $this->jitBool($context, $args[2], 'mkdir() argument #3');
        }

        return JitMkdir::invoke($context, $path, $mode, $recursive);
    }
}
