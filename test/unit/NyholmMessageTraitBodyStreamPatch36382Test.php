<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPUnit\Framework\TestCase;

/** #36382 — MessageTrait $stream → $bodyStream rename is idempotent. */
final class NyholmMessageTraitBodyStreamPatch36382Test extends TestCase
{
    public function testRenameIsIdempotent(): void
    {
        $repo = dirname(__DIR__, 2);
        $script = $repo.'/script/composer/patch-nyholm-message-trait-bodystream-36382.php';
        $tmp = sys_get_temp_dir().'/bodystream_36382_'.getmypid().'.php';
        file_put_contents(
            $tmp,
            "<?php\ntrait T {\n    /** @var StreamInterface|null */\n    private \$stream;\n"
            ."    public function g() { return \$this->stream; }\n}\n"
        );
        exec(sprintf('php %s %s 2>&1', escapeshellarg($script), escapeshellarg($tmp)), $o1, $c1);
        $this->assertSame(0, $c1, implode("\n", $o1));
        $once = file_get_contents($tmp);
        $this->assertStringContainsString('bodyStream avoids Stream', (string) $once);
        $this->assertStringContainsString('private $bodyStream', (string) $once);
        exec(sprintf('php %s %s 2>&1', escapeshellarg($script), escapeshellarg($tmp)), $o2, $c2);
        $this->assertSame(0, $c2, implode("\n", $o2));
        $this->assertSame($once, file_get_contents($tmp));
        @unlink($tmp);
    }
}
