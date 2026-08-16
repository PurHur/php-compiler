<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * GlobIterator('') empty pattern ValueError cites $pattern (#31529).
 *
 * php-src: ext/spl/spl_directory.c / spl_directory.stub.php — string $pattern.
 */
final class Issue31529GlobIteratorEmptyPatternTest extends TestCase
{
    public function testVmEmptyPatternValueErrorMatchesZend(): void
    {
        $code = <<<'PHP'
<?php
error_reporting(E_ALL);
try {
    new GlobIterator('');
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_31529_globiterator_empty_pattern.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "ValueError: GlobIterator::__construct(): Argument #1 (\$pattern) cannot be empty\n",
            $out
        );
    }
}
