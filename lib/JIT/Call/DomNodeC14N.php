<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomC14N;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\DomC14NRuntime;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/** DOMNode::C14N() — user-script AOT (#19467). */
final class DomNodeC14N implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_c14n_invoke_cont');
        if (!VmClassMethod::requireJitUserArgCountRange(
            $context,
            $args,
            'DOMNode::C14N',
            0,
            4
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        DomC14NRuntime::ensureLinked($context);

        return JitDomC14N::invoke($context, ...$args);
    }
}
