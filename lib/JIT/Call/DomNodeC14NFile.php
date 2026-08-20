<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\dom\JitDomC14NFile;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/** DOMNode::C14NFile() — user-script AOT (#32964 / peer DomNodeC14N). */
final class DomNodeC14NFile implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        BasicBlockHelper::ensureOpenInsertBlock($context, 'dom_c14nfile_invoke_cont');
        if (!VmClassMethod::requireJitUserArgCountRange(
            $context,
            $args,
            'DOMNode::C14NFile',
            1,
            5
        )) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }

        return JitDomC14NFile::invoke($context, ...$args);
    }
}
