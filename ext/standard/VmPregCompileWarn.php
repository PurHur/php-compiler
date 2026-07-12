<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * preg_* compile-failure warning text — kept out of {@see VmPregPattern} nested JIT (#16075).
 *
 * php-src: ext/pcre/php_pcre.c
 */
final class VmPregCompileWarn
{
    public static function compileWarningMessage(string $pattern): ?string
    {
        $delimiterMessage = VmPregPattern::patternWarningMessage($pattern);
        if (null !== $delimiterMessage) {
            return $delimiterMessage;
        }
        $parsed = VmPregPattern::parsePhpPattern($pattern);
        if (null === $parsed) {
            return null;
        }
        [$regex, $opts] = $parsed;
        VmPregEngine::compile($regex, $opts);
        $exception = VmPregEngine::consumeLastCompileException();
        if (null === $exception) {
            return null;
        }

        return \sprintf(
            'Compilation failed: %s at offset %d',
            $exception->compileMessage,
            1 + $exception->compileOffset
        );
    }
}
