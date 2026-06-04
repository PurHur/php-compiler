<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\EnumCaseSupport;
use PHPUnit\Framework\TestCase;

/**
 * Top-level global init and `global $name` import must keep enum case objects (#5752).
 */
final class VmGlobalEnumInitTest extends TestCase
{
    public function testGlobalInitAndImportPreserveEnumCase(): void
    {
        $code = <<<'PHP'
<?php
declare(strict_types=1);

enum Color: string { case Red = 'r'; }
$g = Color::Red;
function f(): void {
    global $g;
    if ($g !== Color::Red) {
        throw new Exception('global import lost enum identity');
    }
}
f();
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'global_enum_init.php');
        $runtime->run($block);
        $global = $runtime->vmContext->ensureGlobal('g')->resolveIndirect();
        $this->assertTrue(
            EnumCaseSupport::isEnumCaseVariable($global),
            'script global must store enum case object'
        );
    }

    public function testGlobalAssignThroughVmUpgradesLegacyBackingScalar(): void
    {
        $runtime = new Runtime();
        $runtime->run($runtime->parseAndCompile(<<<'PHP'
<?php
enum Color: string { case Red = 'r'; }
PHP, 'global_enum_decl.php'));
        $enum = $runtime->vmContext->classes['color'];
        $legacy = new VM\Variable(VM\Variable::TYPE_STRING);
        $legacy->string('r');
        $enum->constants['red'] = $legacy;
        $runtime->run($runtime->parseAndCompile('<?php $g = "r";', 'global_enum_assign.php'));
        $global = $runtime->vmContext->ensureGlobal('g')->resolveIndirect();
        $this->assertTrue(
            EnumCaseSupport::isEnumCaseVariable($global),
            'global assign must upgrade legacy backing scalar to enum case'
        );
    }
}
