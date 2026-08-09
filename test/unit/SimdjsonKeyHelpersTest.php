<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** simdjson_key_* JSON-pointer helpers (#27857). */
final class SimdjsonKeyHelpersTest extends TestCase
{
    public function testKeyHelpersOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        try {
            $runtime = new Runtime();
            $code = <<<'PHP'
<?php
$json = '{"a":{"b":[1,2,3]},"n":1}';
echo (int) function_exists('simdjson_key_exists');
echo (int) function_exists('simdjson_key_count');
echo (int) function_exists('simdjson_key_value');
echo (int) simdjson_key_exists($json, '/a/b');
echo (int) simdjson_key_exists($json, 'a/b');
echo (int) !simdjson_key_exists($json, '/missing');
echo (int) (simdjson_key_count($json, '/a/b') === 3);
echo (int) (simdjson_key_value($json, '/a/b/1') === 2);
echo (int) (simdjson_key_value($json, '/a', true) === ['b' => [1, 2, 3]]);
echo (int) class_exists('SimdJsonValueError');
PHP;
            $block = $runtime->parseAndCompile($code, 'simdjson_key.php');
            ob_start();
            $runtime->run($block);
            self::assertSame('1111111111', ob_get_clean());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
                unset($_ENV['PHP_COMPILER_PROFILE']);
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
                $_ENV['PHP_COMPILER_PROFILE'] = $prev;
            }
        }
    }
}
