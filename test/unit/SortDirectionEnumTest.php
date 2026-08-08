<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** SortDirection phantom retirement (#28930, re-#7261). */
final class SortDirectionEnumTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PHP_COMPILER_PROFILE');
        unset($_ENV['PHP_COMPILER_PROFILE']);
        parent::tearDown();
    }

    public function testSortDirectionPhantomAbsentOnProfile84(): void
    {
        $this->assertFalse(CompilerVersion::supportsSortingEnum());
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
var_export(enum_exists('SortDirection', false));
echo "\n";
var_export(class_exists('SortDirection', false));
echo "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'sortdirection_phantom.php'));
        $this->assertSame("false\nfalse\n", ob_get_clean());
    }
}
