<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** Focused JIT PHPT for CallbackFilterIterator unknown-string callback TypeError (#31574). */
final class CallbackFilterIteratorStringCallbackMsg31574JITTest extends BaseTest
{
    protected string $BIN = __DIR__.'/../../bin/jit.php';

    public static function providePHPTests(): \Generator
    {
        $path = __DIR__.'/cases/stdlib/callbackfilteriterator_string_callback_msg_31574.phpt';
        [$name, $code, $sections] = self::parsePHPT(
            $path,
            'callbackfilteriterator_string_callback_msg_31574'
        );
        yield $name => [$name, $code, $sections];
    }
}
