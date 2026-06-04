<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit\VM;

use PHPCompiler\Runtime;
use PHPCompiler\VM\OutputBuffer;
use PHPCompiler\VM\ScriptExit;
use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

final class GlobalsUnsetTest extends TestCase
{
    public function testVmUnsetGlobalsKey(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            <<<'PHP'
<?php
$GLOBALS['k'] = 1;
unset($GLOBALS['k']);
var_export(array_key_exists('k', $GLOBALS));
echo "\n";
var_export(isset($GLOBALS['k']));
PHP,
            'globals_unset.php'
        );
        OutputBuffer::reset();
        ob_start();
        try {
            $runtime->run($block);
        } catch (ScriptExit $e) {
        }
        $out = (string) ob_get_clean();
        $this->assertSame("false\nfalse", trim($out));
    }
}
