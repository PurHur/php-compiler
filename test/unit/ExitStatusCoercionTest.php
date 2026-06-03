<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPCompiler\VM\ScriptExit;
use PHPUnit\Framework\TestCase;

/** @covers issue #4696 */
final class ExitStatusCoercionTest extends TestCase
{
    /**
     * @dataProvider provideCoercedStatus
     */
    public function testExitCoercesZendLegalScalars(string $code, string $expectedOutput, int $expectedStatus): void
    {
        $runtime = new Runtime();
        ob_start();
        try {
            $runtime->run($runtime->parseAndCompile($code, 'exit_coerce.php'));
            $this->fail('Expected ScriptExit');
        } catch (ScriptExit $e) {
            $this->assertSame($expectedStatus, $e->status);
        }
        $this->assertSame($expectedOutput, ob_get_clean());
    }

    public static function provideCoercedStatus(): array
    {
        return [
            'float' => ['<?php exit(1.5);', '1.5', 0],
            'bool true' => ['<?php exit(true);', '1', 0],
            'null' => ['<?php exit(null);', '', 0],
            'bool false' => ['<?php exit(false);', '', 0],
        ];
    }
}
