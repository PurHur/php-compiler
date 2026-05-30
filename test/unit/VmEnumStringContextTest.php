<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Issue #3518 — backed enum case string context and ->value on VM. */
final class VmEnumStringContextTest extends TestCase
{
    public function testBackedEnumEchoAndValueAndIdentity(): void
    {
        $code = <<<'PHP'
<?php
enum Status: string {
    case Active = 'active';
    case Pending = 'pending';
}
echo Status::Active, "\n";
echo Status::Active->value, "\n";
var_dump(Status::Active === Status::Active);
echo Status::Pending, "\n";
echo "done\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'enum_string_context.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertSame(
            "active\nactive\nbool(true)\npending\ndone\n",
            $output
        );
    }

    public function testIntBackedEnumEchoCoercesToString(): void
    {
        $code = <<<'PHP'
<?php
enum N: int {
    case X = 1;
}
echo N::X;
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'enum_int_backed.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertSame('1', $output);
    }
}
