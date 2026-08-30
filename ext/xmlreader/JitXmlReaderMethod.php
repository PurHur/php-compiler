<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xmlreader;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for XMLReader instance/static methods — user-script AOT (#27299). */
final class JitXmlReaderMethod
{
    public static function invoke(Context $context, string $methodLc, JITVariable ...$args): Value
    {
        $result = match ($methodLc) {
            'fromstring', 'xml' => JitXmlReaderUserScript::tryFromString($context, ...$args),
            // leftover of fromUri/fromString (#35907 / #27299) — php-src zim_xmlreader_open
            'open' => JitXmlReaderUserScript::tryOpen($context, ...$args),
            // leftover of fromString (#35900 / #27299) — php-src zim_xmlreader_fromUri
            'fromuri' => JitXmlReaderUserScript::tryFromUri($context, ...$args),
            // leftover of fromString (#35900 / #27299) — php-src zim_xmlreader_fromStream
            'fromstream' => JitXmlReaderUserScript::tryFromStream($context, ...$args),
            'read' => JitXmlReaderUserScript::tryRead($context, ...$args),
            default => null,
        };
        if (null === $result) {
            throw new \LogicException(
                'XMLReader::'.$methodLc.'() user-script AOT requires compile-time source + tracked reader (#27299)'
            );
        }

        return $result;
    }
}
