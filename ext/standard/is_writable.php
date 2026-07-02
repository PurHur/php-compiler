<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** is_writable() / is_writeable() alias — VM via VmStatPath; JIT via stat mode access (#8186, #8990, #14965). */
final class is_writable extends Internal
{
    public function __construct(string $name = 'is_writable')
    {
        parent::__construct($name);
    }

    public function execute(Frame $frame): void
    {
        $fn = $this->getName();
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException($fn.'() requires exactly one argument in this compiler build');
        }
        $path = VmFilestatArg::filenameArgForFrame($frame, 0, $fn);
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmStatPath::isWritable($path));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $fn = $this->getName();
        if (1 !== \count($args)) {
            throw new \LogicException($fn.'() requires exactly one argument in this compiler build');
        }
        $path = JitStringBuiltinArg::lowerPath($context, $args[0], $fn, 0, 'filename');

        return JitStat::pathIsWritable($context, $path);
    }
}
