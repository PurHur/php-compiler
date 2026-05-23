<?php

declare(strict_types=1);

namespace PHPCompiler;

require_once __DIR__.'/VMTest.php';

/**
 * VM echo for array/object/null (issue #71).
 */
final class EchoArrayObjectVMTest extends VMTest
{
    public static function providePHPTests(): \Generator
    {
        $dir = __DIR__.'/cases/language';
        foreach (['echo_array.phpt', 'echo_null.phpt', 'echo_object.phpt'] as $file) {
            $path = $dir.'/'.$file;
            $name = preg_replace('/\.phpt$/', '', $file) ?: $file;
            yield $name => self::parsePHPT($path, $file);
        }
    }
}
