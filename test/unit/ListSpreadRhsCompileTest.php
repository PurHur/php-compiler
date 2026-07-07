<?php

declare(strict_types=1);

namespace Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * listSpreadRhs php-cfg patch and Compiler guard (#6069, #5472).
 */
final class ListSpreadRhsCompileTest extends TestCase
{
    private static string $root;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
    }

    /**
     * @return list<string>
     */
    private function compileAndCollectListSpreadRhsWarnings(string $code, string $filename): array
    {
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
            $block = $runtime->parseAndCompile($code, $filename);
            self::assertNotNull($block);
        } finally {
            restore_error_handler();
            if ($previous !== null) {
                set_error_handler($previous);
            }
        }

        return $warnings;
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

        $warnings = $this->compileAndCollectListSpreadRhsWarnings($code, 'list_byref.php');

        self::assertSame([], $warnings, 'list() compile must not warn on listSpreadRhs (#6069)');
    }

    public function testListSpreadAssignmentCompilesWithoutListSpreadRhsWarnings(): void
    {
        $assign = self::$root.'/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/Assign.php';
        if (!is_readable($assign) || !str_contains((string) file_get_contents($assign), 'listSpreadRhs')) {
            self::markTestSkipped('php-cfg-list-spread overlay not applied');
        }

        $previous = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $code = <<<'PHP'
<?php
$a = [1, 2, 3];
[$head, ...$tail] = $a;
PHP;

            $warnings = $this->compileAndCollectListSpreadRhsWarnings($code, 'list_spread.php');

            self::assertSame([], $warnings, 'list spread compile must not warn on listSpreadRhs (#6069)');
        } finally {
            if (false === $previous) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$previous);
            }
        }
    }

    public function testSimpleAssignCompilesWithoutListSpreadRhsWarningsWhenOverlayMissing(): void
    {
        $assign = self::$root.'/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/Assign.php';
        if (!is_readable($assign)) {
            self::markTestSkipped('vendor/ircmaxell/php-cfg not installed');
        }

        $original = (string) file_get_contents($assign);
        $stripped = preg_replace(
            '/^\s*public \$listSpread(?:Rhs|FromIndex|ExcludedKeys).*$/m',
            '',
            $original
        );
        if ($stripped === $original) {
            self::markTestSkipped('listSpreadRhs overlay already absent');
        }

        file_put_contents($assign, $stripped);
        try {
            $warnings = $this->compileAndCollectListSpreadRhsWarnings(
                <<<'PHP'
<?php
$a = 1;
PHP,
                'simple_assign.php'
            );
        } finally {
            file_put_contents($assign, $original);
        }

        self::assertSame(
            [],
            $warnings,
            'simple assign must not warn on listSpreadRhs when overlay missing (#5472)'
        );
    }
}
