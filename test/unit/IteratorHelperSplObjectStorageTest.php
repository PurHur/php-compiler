<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

final class IteratorHelperSplObjectStorageTest extends TestCase
{
    public function testBootstrapGetFrameFixturesExist(): void
    {
        $root = dirname(__DIR__, 2);
        self::assertFileExists($root.'/test/bootstrap-aot/block_getframe_scope_foreach.php');
        self::assertFileExists($root.'/test/bootstrap-aot/block_getframe_args_contains.php');
        self::assertFileExists($root.'/test/bootstrap-aot/block_getframe_loop.php');
        self::assertFileExists($root.'/test/bootstrap-aot/block_orig_children_foreach.php');
        self::assertFileExists($root.'/test/bootstrap-aot/spl_object_storage_attach.php');
    }
}
