<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * SPINE_CHUNK TUs register static proxies in source order (#36155 / #36166).
 * findSlot must not forward-ref findVariableInParentFrames when Runtime pulls Block in.
 */
final class BlockSpineChunkStaticHelperOrderTest extends TestCase
{
    public function testFindVariableInParentFramesDefinedBeforeFindSlot(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/Block.php');
        $findSlotPos = strpos($source, 'public function findSlot(');
        $parentFramesPos = strpos($source, 'function findVariableInParentFrames(');
        $byNamePos = strpos($source, 'function findVariableInParentFramesByName(');
        $this->assertNotFalse($findSlotPos, 'findSlot');
        $this->assertNotFalse($parentFramesPos, 'findVariableInParentFrames');
        $this->assertNotFalse($byNamePos, 'findVariableInParentFramesByName');
        $this->assertLessThan($findSlotPos, $parentFramesPos);
        $this->assertLessThan($findSlotPos, $byNamePos);
    }
}
