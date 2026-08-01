<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\VM;
use PHPCompiler\VM\Context as VMContext;

/**
 * PHP 8.5+ compile-time E_DEPRECATED for non-canonical cast spellings (#26281).
 *
 * php-src: Zend/zend_language_scanner.l — (integer)/(boolean)/(double)/(binary)
 * emit E_DEPRECATED preferring (int)/(bool)/(float)/(string).
 * RFC: deprecate_non-standard_cast_names.
 */
final class NonCanonicalCastDeprecation
{
    /** @var array<string, string> normalized cast text → Zend message */
    private const MESSAGES = [
        '(integer)' => 'Non-canonical cast (integer) is deprecated, use the (int) cast instead',
        '(boolean)' => 'Non-canonical cast (boolean) is deprecated, use the (bool) cast instead',
        '(double)' => 'Non-canonical cast (double) is deprecated, use the (float) cast instead',
        '(binary)' => 'Non-canonical cast (binary) is deprecated, use the (string) cast instead',
    ];

    public static function emitForSource(string $code, string $filename, VMContext $context): void
    {
        if (!CompilerVersion::supportsNonCanonicalCastDeprecation()) {
            return;
        }
        if (!self::sourceMayContainAlias($code)) {
            return;
        }
        if (NestedJitCompileScope::isActive()) {
            return;
        }
        if (ReferenceProfileTokenScan::shouldSkipReferenceProfileReject($code, $filename)) {
            return;
        }
        if (!\function_exists('token_get_all')) {
            return;
        }

        $frame = self::resolveEmitFrame($context, $filename);
        $castIds = self::castTokenIds();
        foreach (token_get_all($code) as $token) {
            if (!\is_array($token) || !isset($castIds[$token[0]])) {
                continue;
            }
            $normalized = strtolower(preg_replace('/\s+/', '', $token[1]) ?? $token[1]);
            $message = self::MESSAGES[$normalized] ?? null;
            if (null === $message) {
                continue;
            }
            $line = isset($token[2]) ? (int) $token[2] : 0;
            $context->errors->internalDeprecated(
                $message,
                $context,
                $frame,
                $filename,
                $line
            );
        }
    }

    private static function sourceMayContainAlias(string $code): bool
    {
        return str_contains($code, 'integer')
            || str_contains($code, 'boolean')
            || str_contains($code, 'double')
            || str_contains($code, 'binary');
    }

    /** @return array<int, true> */
    private static function castTokenIds(): array
    {
        $ids = [
            \T_INT_CAST => true,
            \T_BOOL_CAST => true,
            \T_DOUBLE_CAST => true,
            \T_STRING_CAST => true,
        ];

        return $ids;
    }

    private static function resolveEmitFrame(VMContext $context, string $filename): Frame
    {
        $vm = VM::running();
        if ($vm instanceof VM) {
            $frame = $vm->builtinHandlerFrame();
            if (null !== $frame) {
                return $frame;
            }
            $frames = $context->runStackFrames();
            if ([] !== $frames) {
                return $frames[0];
            }
        }

        $block = new Block(null);
        $frame = new Frame(null, $block, null);
        $frame->vmContext = $context;
        $frame->scriptPath = $filename;

        return $frame;
    }
}
