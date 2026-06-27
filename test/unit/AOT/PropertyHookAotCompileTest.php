<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit\AOT;

use PHPCompiler\PropertyHookProfileSkipTrait;
use PHPCompiler\Runtime;
use PHPCompiler\Web\LiteralIncludeDiscovery;
use PHPUnit\Framework\TestCase;

/**
 * AOT compile smoke for property-hook lowering (#3723).
 */
final class PropertyHookAotCompileTest extends TestCase
{
    use PropertyHookProfileSkipTrait;

    protected function setUp(): void
    {
        $this->skipUnlessPropertyHooks();
    }

    public function testLiteralIncludeDiscoveryParsesPropertyHookSyntax(): void
    {
        $src = <<<'PHP'
<?php
class User {
    public string $email {
        set (string $value) {
            $this->email = $value;
        }
    }
}
PHP;
        $path = sys_get_temp_dir().'/property_hook_aot_'.bin2hex(random_bytes(4)).'.php';
        file_put_contents($path, $src);
        try {
            $runtime = new Runtime(Runtime::MODE_AOT);
            $includes = LiteralIncludeDiscovery::discoverDirectAbsolutePaths($runtime, $path);
            self::assertSame([], $includes);
        } finally {
            @unlink($path);
        }
    }

    public function testPropertyHookScriptCompilesForAot(): void
    {
        $src = <<<'PHP'
<?php
class User {
    public string $email {
        set (string $value) {
            if (!str_contains($value, '@')) {
                echo "reject\n";
                return;
            }
            $this->email = $value;
        }
    }
}
$u = new User();
$u->email = 'bad';
$u->email = 'a@b.c';
echo $u->email, "\n";
PHP;
        $rt = new Runtime(Runtime::MODE_AOT);
        $block = $rt->parseAndCompileEmitSmoke($src, 'property_hook_aot_compile.php');
        self::assertNotNull($block);
    }
}
