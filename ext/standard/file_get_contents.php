<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\JitRequestBody;
use PHPCompiler\Web\Superglobals;
use PHPLLVM\Value;

/**
 * file_get_contents() — php://input reads REQUEST_BODY via getenv (issue #289, #291).
 */
final class file_get_contents extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('file_get_contents() requires exactly one argument in this compiler build');
        }
        $v = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $v->type) {
            throw new \LogicException('file_get_contents() requires a string filename in this compiler build');
        }
        $filename = $v->toString();
        if ('php://input' === $filename) {
            $frame->returnVar->string(Superglobals::readRequestBody());

            return;
        }
        $data = VmFs::fileGetContents($filename);
        if (false === $data) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($data);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('file_get_contents() requires exactly one argument in this compiler build');
        }
        $arg = $args[0];
        $literal = JitStringArg::compileTimeLiteral($arg);
        if ('php://input' === $literal) {
            return JitRequestBody::readPhpInput($context);
        }

        return JitFileGetContents::invoke(
            $context,
            JitStringArg::lower($context, $arg, 'file_get_contents() filename')
        );
    }
}
