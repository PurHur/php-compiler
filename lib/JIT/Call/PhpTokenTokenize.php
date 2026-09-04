<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * PhpToken::tokenize(string $code, int $flags = 0): array — JIT/AOT (#27263).
 *
 * Dispatch via {@see Context::$extensionLowering} so lib/JIT does not import
 * {@code ext\tokenizer} (#36204). php-src: ext/tokenizer/tokenizer.c — PhpToken::tokenize
 * VM SSOT: {@see \PHPCompiler\ext\tokenizer\PhpTokenTokenize}
 */
final class PhpTokenTokenize implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return $context->extensionLowering->requireTokenizer()->tokenize($context, ...$args);
    }
}
