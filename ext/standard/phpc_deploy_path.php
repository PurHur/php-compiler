<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPCompiler\Web\DeployRoot;
use PHPLLVM\Value;

/**
 * phpc_deploy_path() — resolve paths under PHPC_DEPLOY_ROOT for deployed AOT binaries (#585).
 */
final class phpc_deploy_path extends Internal
{
    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('phpc_deploy_path() requires exactly two arguments in this compiler build');
        }
        $rel = $frame->calledArgs[0]->resolveIndirect();
        $fallback = $frame->calledArgs[1]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_STRING !== $rel->type || Variable::TYPE_STRING !== $fallback->type) {
            throw new \LogicException('phpc_deploy_path() requires two strings in this compiler build');
        }
        $frame->returnVar->string(
            DeployRoot::resolvePath($rel->toString(), $fallback->toString())
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('phpc_deploy_path() requires exactly two arguments in this compiler build');
        }
        if (JITVariable::TYPE_STRING !== $args[0]->type || JITVariable::TYPE_STRING !== $args[1]->type) {
            throw new \LogicException('phpc_deploy_path() requires two strings in this compiler build');
        }

        $this->jitString($context, $args[0], 'phpcdeploypath() argument #1');
        return JitDeployPath::invoke(
            $context,
            $context->helper->loadValue($args[0]),
            $context->helper->loadValue($args[1])
        );
    }
}
