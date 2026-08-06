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
            'fromstring' => JitXmlReaderUserScript::tryFromString($context, ...$args),
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
