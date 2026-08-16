<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * SplObjectStorage::offsetSet(null)/dim TypeError cites offsetSet, not attach (#31509).
 *
 * php-src: ext/spl/spl_observer.c — SPL_METHOD(SplObjectStorage, offsetSet) / write_dimension
 */
final class Issue31509SplObjectStorageOffsetSetNullTypeErrorTest extends TestCase
{
    public function testVmTypeErrorMessagesMatchZend(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_splobjectstorage_offsetset_null.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'maintainer_gap_splobjectstorage_offsetset_null.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "off:TypeError: SplObjectStorage::offsetSet(): Argument #1 (\$object) must be of type object, null given\n"
            ."dim:TypeError: SplObjectStorage::offsetSet(): Argument #1 (\$object) must be of type object, null given\n",
            $out
        );
        $this->assertStringNotContainsString('::attach()', $out);
    }
}
