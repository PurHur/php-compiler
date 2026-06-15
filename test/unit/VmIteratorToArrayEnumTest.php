<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\Variable;
use PHPUnit\Framework\TestCase;

/** Enum case array literals after iterator method calls (#5636). */
final class VmIteratorToArrayEnumTest extends TestCase
{
    public function testEnumArrayLiteralAfterArrayIteratorCount(): void
    {
        $code = <<<'PHP'
<?php
$it = new ArrayIterator([1, 2, 3]);
echo $it->count(), "\n";

enum E: int { case A = 1; case B = 2; }
var_export([E::A, E::B]);
echo "\n";
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'iterator_enum.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertStringContainsString('\\E::A', $output);
        $this->assertStringNotContainsString('NULL', $output);
    }

    public function testCompiledBlockRetainsEnumCaseConstantsForArrayElements(): void
    {
        $code = <<<'PHP'
<?php
$it = new ArrayIterator([1, 2, 3]);
echo $it->count(), "\n";
enum E: int { case A = 1; case B = 2; }
var_export([E::A, E::B]);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'probe.php');
        $enumConstCount = 0;
        $types = [];
        $enumObjectSlots = [];
        foreach ($block->constants as $slot => $const) {
            $types[$slot] = $const->type;
            if (Variable::TYPE_ENUM_CASE === $const->type) {
                ++$enumConstCount;
            }
            if (Variable::TYPE_OBJECT === $const->type) {
                try {
                    $enumObjectSlots[$slot] = $const->toObject()->isEnumCase ? 'enum' : 'obj';
                } catch (\LogicException) {
                    $enumObjectSlots[$slot] = 'empty';
                }
            }
        }
        $this->assertGreaterThanOrEqual(
            2,
            $enumConstCount + \count(array_filter($enumObjectSlots, static fn (string $v): bool => 'enum' === $v)),
            'expected folded enum case block constants, saw types: '.json_encode($types)
                .' enumObjects: '.json_encode($enumObjectSlots)
        );
    }
}
