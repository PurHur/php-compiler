<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * ArrayObject::asort()/ksort() non-int $flags → TypeError (#31035).
 *
 * php-src: ext/spl/spl_array.c — Z_PARAM_LONG on $flags.
 */
final class Issue31035ArrayObjectSortFlagsTest extends TestCase
{
    public function testVmTypeErrorWordingMatchesZend(): void
    {
        $code = <<<'PHP'
<?php
$ao = new ArrayObject([3, 1, 2]);
foreach (['asort', 'ksort'] as $m) {
    try {
        $ao->$m('x');
        echo "$m: SILENT\n";
    } catch (Throwable $e) {
        echo "$m: ", get_class($e), ": ", $e->getMessage(), "\n";
    }
}
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_31035_arrayobject_sort_flags.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertStringContainsString(
            'asort: TypeError: ArrayObject::asort(): Argument #1 ($flags) must be of type int, string given',
            $out
        );
        $this->assertStringContainsString(
            'ksort: TypeError: ArrayObject::ksort(): Argument #1 ($flags) must be of type int, string given',
            $out
        );
        $this->assertStringNotContainsString('LogicException', $out);
    }
}
