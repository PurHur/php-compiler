<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\ResourceSupport;
use PHPCompiler\VM\Variable as VMVariable;
use PHPUnit\Framework\TestCase;

/** Assigning an open stream over a closed Resource object zval (#16271). */
final class ResourceReopenAssignTest extends TestCase
{
    public function testAssignOpenStreamOverClosedResourceReplacesObject(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;

        $closed = new VMVariable();
        $handle1 = \PHPCompiler\ext\standard\VmFs::fopen('php://memory', 'r+');
        $this->assertIsInt($handle1);
        $closed->streamHandle($handle1, $ctx);
        $closedId = $closed->toObject()->id;
        \PHPCompiler\ext\standard\VmFs::fclose($handle1);
        $this->assertTrue(ResourceSupport::isClosedVmResource($closed));

        $open = new VMVariable();
        $handle2 = \PHPCompiler\ext\standard\VmFs::fopen('php://memory', 'r+');
        $this->assertIsInt($handle2);
        $open->streamHandle($handle2, $ctx);
        $openId = $open->toObject()->id;
        $this->assertNotSame($closedId, $openId);
        $this->assertTrue(ResourceSupport::isOpenStreamResource($open));

        $closed->copyFrom($open);

        $this->assertSame($openId, $closed->toObject()->id);
        $this->assertTrue(ResourceSupport::isOpenStreamResource($closed));
        $this->assertSame($handle2, ResourceSupport::resolveHandle($closed));
    }

    public function testReopenSameVariableAfterFcloseViaVmScript(): void
    {
        ob_start();
        $runtime = new Runtime();
        $runtime->run($runtime->parseAndCompile(<<<'PHP'
<?php
$f = fopen('php://memory', 'r+');
fclose($f);
$f = fopen('php://memory', 'r+');
echo gettype($f), "\n";
var_export(fscanf($f, '%d'));
echo "\n";
PHP, 'resource_reopen_assign.php'));
        $output = ob_get_clean();

        $this->assertSame("resource\nfalse\n", $output);
    }
}
