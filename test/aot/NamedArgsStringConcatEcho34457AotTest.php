<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/AotTest.php';

/**
 * AOT: named string args + echo $a.$b must not segfault (#34457).
 *
 * @group llvm
 * @group aot
 * @group aot-link
 */
final class NamedArgsStringConcatEcho34457AotTest extends AotTest
{
    public static function providePHPTests(): \Generator
    {
        $basename = 'named_args_string_concat_echo_34457.phpt';
        $path = dirname(__DIR__).'/fixtures/aot/cases/'.$basename;
        if (!is_file($path)) {
            throw new \RuntimeException('named args string concat echo 34457 AOT: missing fixture '.$path);
        }
        yield pathinfo($basename, PATHINFO_FILENAME) => self::parsePHPT($path, $basename);
    }
}
