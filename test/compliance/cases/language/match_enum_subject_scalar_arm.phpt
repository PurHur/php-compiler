--TEST--
Language: match() enum-case subject must not match scalar int arms (issue #9716, Zend/zend_execute.c)
--FILE--
<?php
declare(strict_types=1);

enum F: int { case X = 1; }

echo match (F::X) {
    1 => 'int_hit',
    F::X => 'case_hit',
    default => 'miss',
}, "\n";
?>
--EXPECT--
case_hit
