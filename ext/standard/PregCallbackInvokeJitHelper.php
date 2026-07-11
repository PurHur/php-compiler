<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * JIT callback invoke trampoline — LLVM body registered by {@see PregMatchRuntime} (#13736).
 *
 * php-src: ext/pcre/php_pcre.c — php_pcre_replace_impl callback form
 */
final class PregCallbackInvokeJitHelper
{
  /**
   * @internal LLVM implements this symbol for nested JIT preg_replace_callback()
   */
    public static function invoke(int $callbackFnAddr, HashTable $matches): string
    {
        throw new \LogicException('PregCallbackInvokeJitHelper::invoke is LLVM-only (#13736)');
    }
}
