<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

final class ArrayObjectGetIteratorAfterSetTest extends TestCase
{
    public function testVarExportAfterVoidSetIteratorClass(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);
$ao = new ArrayObject([1, 2, 3]);
$ao->setIteratorClass('ArrayIterator');
echo var_export($ao->getIteratorClass(), true);
PHP;
        $runtime = new Runtime();
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'ao.php'));
        self::assertSame("'ArrayIterator'", ob_get_clean());
    }
}
