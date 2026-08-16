<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/../BaseTest.php';

/** Focused VM PHPT for CallbackFilterIterator unknown-string callback TypeError (#31574). */
final class CallbackFilterIteratorStringCallbackMsg31574VMTest extends BaseTest
{
    protected string $BIN = __DIR__.'/../../bin/vm.php';

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
