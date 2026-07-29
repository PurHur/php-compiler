<?php
declare(strict_types=1);
// Repro #24614 — serialize(BcMath\Number) value-only; scale recovered on unserialize.
$n = new BcMath\Number('1.50');
$wire = serialize($n);
echo $wire, "\n";
$expected = 'O:13:"BcMath\\Number":1:{s:5:"value";s:4:"1.50";}';
echo 'match=', ($wire === $expected ? '1' : '0'), "\n";
$round = unserialize($wire);
echo 'value=', $round->value, ' scale=', $round->scale, "\n";
// Cross-engine: Zend-shaped payload (no scale field).
$fromZend = unserialize('O:13:"BcMath\\Number":1:{s:5:"value";s:4:"2.00";}');
echo 'zend_value=', $fromZend->value, ' zend_scale=', $fromZend->scale, "\n";
$bag = $n->__serialize();
echo 'keys=', implode(',', array_keys($bag)), "\n";
