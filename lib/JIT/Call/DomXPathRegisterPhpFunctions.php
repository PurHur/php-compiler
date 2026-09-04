<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DOMXPath::registerPhpFunctions() — user-script AOT (#27575). */
final class DomXPathRegisterPhpFunctions implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return $context->extensionLowering->requireDom()->invokeCall(
            $context,
            'xpath.registerPhpFunctions',
            ...$args
        );
    }
}
