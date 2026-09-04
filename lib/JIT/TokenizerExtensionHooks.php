<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Value;

/**
 * tokenizer extension surfaces needed by lib/JIT Call (#36204).
 *
 * Implemented in {@code ext/tokenizer/JitTokenizerExtensionHooksFacade.php}; Call
 * PhpToken* files must not import {@code ext\tokenizer}.
 */
interface TokenizerExtensionHooks
{
    /** PhpToken::tokenize() compile-time / thin-AOT materialization. */
    public function tokenize(Context $context, Variable ...$args): Value;

    /** PhpToken::__construct() thin-AOT. */
    public function construct(Context $context, Variable ...$args): Value;

    /** PhpToken::getTokenName() thin-AOT. */
    public function getTokenName(Context $context, Variable ...$args): Value;
}
