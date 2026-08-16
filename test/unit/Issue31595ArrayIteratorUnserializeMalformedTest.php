<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * ArrayIterator/ArrayObject::unserialize malformed → UnexpectedValueException (#31595).
 *
 * php-src: ext/spl/spl_array.c — zim_ArrayObject_unserialize
 */
final class Issue31595ArrayIteratorUnserializeMalformedTest extends TestCase
{
    public function testVmMalformedUnserializeThrowsUnexpectedValueException(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_arrayiterator_unserialize_malformed.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'maintainer_gap_arrayiterator_unserialize_malformed.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "ArrayIterator UnexpectedValueException:Error at offset 1 of 1 bytes\n"
            ."ArrayObject UnexpectedValueException:Error at offset 1 of 1 bytes\n",
            $out
        );
    }

    public function testVmLegacySerializeRoundTripRestoresStorage(): void
    {
        $code = <<<'PHP'
<?php
$a = new ArrayIterator([1, 2]);
$wire = $a->serialize();
echo str_starts_with($wire, 'x:') ? "wire_ok\n" : "wire_bad:$wire\n";
$b = new ArrayIterator([]);
$b->unserialize($wire);
echo json_encode(iterator_to_array($b)), "\n";
$c = new ArrayObject(['k' => 9]);
$c2 = new ArrayObject([]);
$c2->unserialize($c->serialize());
echo json_encode($c2->getArrayCopy()), "\n";
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_31595_roundtrip.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame("wire_ok\n[1,2]\n{\"k\":9}\n", $out);
    }
}
