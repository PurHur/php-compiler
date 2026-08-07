<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xsl;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** Dispatch XSLTProcessor user-script AOT methods (#20392). */
final class JitXsltMethod
{
    public static function invoke(Context $context, string $methodLc, JITVariable ...$args): Value
    {
        $result = match ($methodLc) {
            'hasexsltsupport' => JitXsltUserScript::tryHasExsltSupport($context, ...$args),
            'setsecurityprefs' => JitXsltUserScript::trySetSecurityPrefs($context, ...$args),
            'getsecurityprefs' => JitXsltUserScript::tryGetSecurityPrefs($context, ...$args),
            'setprofiling' => JitXsltUserScript::trySetProfiling($context, ...$args),
            'importstylesheet' => JitXsltUserScript::tryImportStylesheet($context, ...$args),
            'transformtoxml' => JitXsltUserScript::tryTransformToXml($context, ...$args),
            default => null,
        };
        if (null === $result) {
            throw new \LogicException(
                'XSLTProcessor::'.$methodLc.'() user-script AOT requires a tracked host processor'
                .(('setsecurityprefs' === $methodLc) ? ' and compile-time int prefs' : '')
                .(('setprofiling' === $methodLc) ? ' and compile-time ?string filename' : '')
                .(('importstylesheet' === $methodLc) ? ' and compile-time stylesheet XML' : '')
                .(('transformtoxml' === $methodLc) ? ' and compile-time source document XML' : '')
                .' (#20392/#22272/#22367/#27392)'
            );
        }

        return $result;
    }
}
