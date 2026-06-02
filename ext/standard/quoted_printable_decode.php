<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringQuotPrint;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** quoted_printable_decode() — MIME quoted-printable (php-src ext/standard/quot_print.c). */
final class quoted_printable_decode extends Internal
{
    public function __construct()
    {
        parent::__construct('quoted_printable_decode');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('quoted_printable_decode() requires exactly one argument in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $data = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_STRING !== $data->type) {
            throw new \LogicException('quoted_printable_decode() only supports strings in this compiler build');
        }
        $frame->returnVar->string(VmString::quoted_printable_decode($data->toString()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('quoted_printable_decode() requires exactly one argument in this compiler build');
        }
        StringQuotPrint::ensureLinked($context);

        return JitQuotedPrintableDecode::decode(
            $context,
            $this->jitString($context, $args[0], 'quoted_printable_decode() argument #1')
        );
    }
}
