<?php

declare(strict_types=1);

namespace Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * listSpreadRhs php-cfg patch must be applied before compile (#6069).
 */
final class ListSpreadRhsCompileTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    public function testListSpreadRhsPropertyPresentAfterApplyPatches(): void
    {
        $assign = self::$root.'/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/Assign.php';
        $parser = self::$root.'/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php';
        if (!is_readable($assign) || !is_readable($parser)) {
            self::markTestSkipped('vendor/ircmaxell/php-cfg not installed');
        }

        $assignBody = (string) file_get_contents($assign);
        self::assertStringContainsString(
            'public $listSpreadRhs',
            $assignBody,
            'Assign.php must declare listSpreadRhs after apply-patches (#6069)'
        );

        $parserBody = (string) file_get_contents($parser);
        self::assertStringContainsString(
            'listSpreadExcludedKeys = $excludedKeys',
            $parserBody,
            'Parser must lower list spread destructuring (#6069)'
        );
    }

    public function testListByRefAssignmentCompilesWithoutListSpreadRhsWarnings(): void
    {
        $assign = self::$root.'/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/Assign.php';
        if (!is_readable($assign) || !str_contains((string) file_get_contents($assign), 'listSpreadRhs')) {
            self::markTestSkipped('php-cfg-list-spread overlay not applied');
        }

        $code = <<<'PHP'
<?php
$a = [1, 2];
list($x, &$y) = $a;
PHP;

        $warnings = [];
        $previous = set_error_handler(
            static function (int $errno, string $errstr) use (&$warnings): bool {
                if ($errno === E_WARNING && str_contains($errstr, 'listSpreadRhs')) {
                    $warnings[] = $errstr;
                }

                return false;
            }
        );
        try {
            $runtime = new Runtime();
            $block = $runtime->parseAndCompile($code, 'list_byref.php');
            self::assertNotNull($block);
        } finally {
            restore_error_handler();
            if ($previous !== null) {
                set_error_handler($previous);
            }
        }

        self::assertSame([], $warnings, 'list() compile must not warn on listSpreadRhs (#6069)');
    }

    public function testListSpreadAssignmentCompilesWithoutListSpreadRhsWarnings(): void
    {
        $assign = self::$root.'/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/Assign.php';
        if (!is_readable($assign) || !str_contains((string) file_get_contents($assign), 'listSpreadRhs')) {
            self::markTestSkipped('php-cfg-list-spread overlay not applied');
        }

        $code = <<<'PHP'
<?php
$a = [1, 2, 3];
[$head, ...$tail] = $a;
PHP;

        $warnings = [];
        $previous = set_error_handler(
            static function (int $errno, string $errstr) use (&$warnings): bool {
                if ($errno === E_WARNING && str_contains($errstr, 'listSpreadRhs')) {
                    $warnings[] = $errstr;
                }

                return false;
            }
        );
        try {
            $runtime = new Runtime();
            $block = $runtime->parseAndCompile($code, 'list_spread.php');
            self::assertNotNull($block);
        } finally {
            restore_error_handler();
            if ($previous !== null) {
                set_error_handler($previous);
            }
        }

        self::assertSame([], $warnings, 'list spread compile must not warn on listSpreadRhs (#6069)');
    }
}
