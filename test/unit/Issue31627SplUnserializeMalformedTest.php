<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * SplObjectStorage/SplDoublyLinkedList/SplQueue::unserialize malformed → UnexpectedValueException (#31627).
 *
 * php-src: ext/spl/spl_observer.c + spl_dllist.c — legacy unserialize
 */
final class Issue31627SplUnserializeMalformedTest extends TestCase
{
    public function testVmMalformedUnserializeThrowsUnexpectedValueException(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_spl_unserialize_malformed.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'maintainer_gap_spl_unserialize_malformed.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "SplObjectStorage UnexpectedValueException:Error at offset 1 of 1 bytes\n"
            ."SplDoublyLinkedList UnexpectedValueException:Error at offset 0 of 1 bytes\n"
            ."SplQueue UnexpectedValueException:Error at offset 0 of 1 bytes\n",
            $out
        );
    }

    public function testVmLegacySerializeRoundTripRestoresStorage(): void
    {
        $code = <<<'PHP'
<?php
$s = new SplObjectStorage();
$o = new stdClass();
$s->attach($o, 'inf');
$wire = $s->serialize();
echo str_starts_with($wire, 'x:') ? "sos_wire_ok\n" : "sos_wire_bad:$wire\n";
$s2 = new SplObjectStorage();
$s2->unserialize($wire);
echo 'sos_count=', $s2->count();
foreach ($s2 as $obj) {
    echo ' info=', $s2[$obj], "\n";
}

$d = new SplDoublyLinkedList();
$d->push(1);
$d->push(2);
$dWire = $d->serialize();
echo str_starts_with($dWire, 'i:') ? "dll_wire_ok\n" : "dll_wire_bad:$dWire\n";
$d2 = new SplDoublyLinkedList();
$d2->unserialize($dWire);
echo 'dll=', implode(',', iterator_to_array($d2)), "\n";

$q = new SplQueue();
$q->enqueue('a');
$qWire = $q->serialize();
$q2 = new SplQueue();
$q2->unserialize($qWire);
echo 'q=', implode(',', iterator_to_array($q2)), "\n";
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_31627_roundtrip.php');
        ob_start();
        $rt->run($block);
        $out = ob_get_clean();
        $this->assertSame(
            "sos_wire_ok\n"
            ."sos_count=1 info=inf\n"
            ."dll_wire_ok\n"
            ."dll=1,2\n"
            ."q=a\n",
            $out
        );
    }
}
