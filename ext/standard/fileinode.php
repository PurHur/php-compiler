<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** fileinode() — VM via stat; JIT/AOT via libc stat st_ino (php-src ext/standard/filestat.c). */
final class fileinode extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('fileinode() requires exactly one argument in this compiler build');
        }
        $path = VmString::coerceTypedStringBuiltinArg($frame->calledArgs[0], 'fileinode', 0, 'filename');
        if (null === $frame->returnVar) {
            return;
        }
        $inode = VmFs::fileInode($path);
        if (false === $inode) {
            VmFilestatFailure::warnPathStatFailed($frame, 'fileinode', $path, false);
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->int($inode);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('fileinode() requires exactly one argument in this compiler build');
        }
        $path = JitStringBuiltinArg::lowerTypedString($context, $args[0], 'fileinode', 0, 'filename');

        return JitFileinode::invoke($context, $path);
    }
}
