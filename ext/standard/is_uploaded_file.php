<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** is_uploaded_file() — VM via VmFs; JIT/AOT via __compiler_is_uploaded_file (issue #2204). */
final class is_uploaded_file extends Internal
{
    public function __construct()
    {
        parent::__construct('is_uploaded_file');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('is_uploaded_file() requires exactly one argument in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $pathVar = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING !== $pathVar->type) {
            throw new \LogicException('is_uploaded_file() requires a string path in this compiler build');
        }
        $frame->returnVar->bool(VmFs::isValidUploadTempPath($pathVar->toString()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('is_uploaded_file() requires exactly one argument in this compiler build');
        }
        $path = $this->jitString($context, $args[0], 'is_uploaded_file() path');

        return JitIsUploadedFile::invoke($context, $path);
    }
}
