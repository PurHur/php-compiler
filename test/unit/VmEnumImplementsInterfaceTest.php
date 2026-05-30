<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** VM enum case objects with interface implementation (#3373). */
final class VmEnumImplementsInterfaceTest extends TestCase
{
    public function testEnumCaseInstanceMethodAndInstanceof(): void
    {
        $code = <<<'PHP'
<?php
interface HasName {
    public function label(): string;
}

enum Status: string implements HasName {
    case Open = 'open';

    public function label(): string {
        return $this->name;
    }
}

echo Status::Open->label();
echo Status::Open instanceof HasName ? '1' : '0';
echo Status::Open;
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'enum_iface.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertSame('Open1open', $output);
    }
}
