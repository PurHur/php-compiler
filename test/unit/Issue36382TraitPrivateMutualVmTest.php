<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPUnit\Framework\TestCase;

/**
 * #36382 — VM: private trait→trait calls use composing-class scope (zend_traits.c).
 *
 * @group vm
 */
final class Issue36382TraitPrivateMutualVmTest extends TestCase
{
    public function testPrivateTraitMethodsCanCallEachOther(): void
    {
        $repo = dirname(__DIR__, 2);
        $src = $repo.'/test/repro/issue_36382_trait_private_mutual.php';
        $this->assertFileExists($src);
        $cmd = sprintf(
            'php -d memory_limit=256M %s %s 2>&1',
            escapeshellarg($repo.'/bin/vm.php'),
            escapeshellarg($src)
        );
        exec($cmd, $lines, $ec);
        $this->assertSame(0, $ec, implode("\n", $lines));
        $this->assertSame('text/plain', trim(implode("\n", $lines)));
    }
}
