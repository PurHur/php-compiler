<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPUnit\Framework\TestCase;

/** #36382 — MessageTrait::getBody spl_object_id cache patch is idempotent. */
final class NyholmMessageTraitGetBodyPatch36382Test extends TestCase
{
    public function testPatchIsIdempotentOnUpstreamShape(): void
    {
        $repo = dirname(__DIR__, 2);
        $script = $repo.'/script/composer/patch-nyholm-message-trait-getbody-36382.php';
        $this->assertFileExists($script);
        $tmp = sys_get_temp_dir().'/message_trait_getbody_36382_'.getmypid().'.php';
        $src = <<<'PHP'
<?php
namespace Nyholm\Psr7;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\MessageInterface;
trait MessageTrait
{
    /** @var StreamInterface|null */
    private $stream;

    public function getBody(): StreamInterface
    {
        if (null === $this->stream) {
            $this->stream = Stream::create('');
        }

        return $this->stream;
    }
}
PHP;
        file_put_contents($tmp, $src);
        exec(sprintf('php %s %s 2>&1', escapeshellarg($script), escapeshellarg($tmp)), $o1, $c1);
        $this->assertSame(0, $c1, implode("\n", $o1));
        $once = file_get_contents($tmp);
        $this->assertStringContainsString('AOT (#36382): getBody spl_object_id cache', (string) $once);
        $this->assertStringContainsString('spl_object_id', (string) $once);
        exec(sprintf('php %s %s 2>&1', escapeshellarg($script), escapeshellarg($tmp)), $o2, $c2);
        $this->assertSame(0, $c2, implode("\n", $o2));
        $this->assertSame($once, file_get_contents($tmp));
        @unlink($tmp);
    }
}
