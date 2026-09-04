<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * PhpToken::__construct(int $id, string $text, int $line = -1, int $pos = -1) — JIT/AOT (#27263).
 *
 * Dispatch via {@see Context::$extensionLowering} so lib/JIT does not import
 * {@code ext\tokenizer} (#36204). php-src: ext/tokenizer/tokenizer.c — PhpToken::__construct
 * VM SSOT: {@see \PHPCompiler\ext\tokenizer\PhpTokenConstruct}
 */
final class PhpTokenConstruct implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return $context->extensionLowering->requireTokenizer()->construct($context, ...$args);
    }
}
