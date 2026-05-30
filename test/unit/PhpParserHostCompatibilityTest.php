<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Host PHP + nikic/php-parser Lexer compatibility (#113).
 */
final class PhpParserHostCompatibilityTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    public function testLexerInstantiatesOnHostPhp(): void
    {
        $autoload = self::$root.'/vendor/autoload.php';
        if (!is_file($autoload)) {
            $this->markTestSkipped('vendor/ not installed');
        }

        require_once $autoload;
        $lexer = new \PhpParser\Lexer();
        $this->assertInstanceOf(\PhpParser\Lexer::class, $lexer);
    }

    public function testPhp82TokenizerConstantsExistOnHost(): void
    {
        if (version_compare(PHP_VERSION, '8.1.0', '<')) {
            $this->markTestSkipped('PHP 8.1+ required for tokenizer probe');
        }

        foreach ([
            'T_OPEN_TAG_WITH_ECHO',
            'T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG',
            'T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG',
            'T_READONLY',
        ] as $token) {
            $this->assertTrue(\defined($token), $token.' must exist on host PHP '.PHP_VERSION);
        }
    }

    public function testVmEntryRunsOnHost(): void
    {
        $autoload = self::$root.'/vendor/autoload.php';
        if (!is_file($autoload)) {
            $this->markTestSkipped('vendor/ not installed');
        }

        $example = self::$root.'/examples/000-HelloWorld/example.php';
        if (!is_file($example)) {
            $this->markTestSkipped('HelloWorld example missing');
        }

        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg(self::$root.'/bin/vm.php').' '
            .escapeshellarg($example).' 2>&1';
        $output = shell_exec($cmd);
        $this->assertIsString($output);
        $this->assertStringContainsString('Hello World', $output);
        $this->assertStringNotContainsString('Undefined constant', $output);
        $this->assertStringNotContainsString('Fatal error', $output);
    }
}
