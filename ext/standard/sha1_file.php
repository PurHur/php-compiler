<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** sha1_file() — hash file contents (issue #3590, ext/standard/md5.c parity). */
final class sha1_file extends Internal
{
    public function __construct()
    {
        parent::__construct('sha1_file');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('sha1_file() requires one or two arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $path = VmFilestatArg::coerceFilenameArg($frame->calledArgs[0], 'sha1_file');
        $raw = false;
        if (2 === $argc) {
            $rawArg = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN !== $rawArg->type) {
                throw new \LogicException('sha1_file() raw_output must be boolean in this compiler build');
            }
            $raw = $rawArg->toBool();
        }
        $result = VmHashFile::hashFile('sha1', $path, $raw);
        if (false === $result) {
            VmStreamOpenFailure::warnFailedToOpen($frame, 'sha1_file', $path);
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($result);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (\count($args) < 1 || \count($args) > 2) {
            throw new \LogicException('sha1_file() requires one or two arguments in this compiler build');
        }
        $raw = $context->getTypeFromString('int1')->constInt(0, false);
        if (isset($args[1])) {
            $raw = JitBoolArg::lower($context, $args[1], 'sha1_file() raw_output');
        }
        $path = JitFilestatArg::lowerFilename($context, $args[0], 'sha1_file');

        return JitHashFile::sha1($context, $path, $raw);
    }
}
