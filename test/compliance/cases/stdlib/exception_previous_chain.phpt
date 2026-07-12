--TEST--
Stdlib: Exception three-arg ctor + getPrevious() chain (#5104, zend_exceptions.c)
--FILE--
<?php
declare(strict_types=1);

$inner = new Exception('inner');
$outer = new Exception('outer', 0, $inner);

$prev = $outer->getPrevious();
echo 'previous_is_exception=', ($prev instanceof Exception) ? 'true' : 'false', "\n";
echo 'previous_message=', $prev instanceof Exception ? $prev->getMessage() : 'null', "\n";
echo 'inner_previous_null=', (null === $inner->getPrevious()) ? 'true' : 'false', "\n";

$chain = (string) $outer;
echo 'chain_has_next=', (str_contains($chain, 'Next Exception')) ? 'true' : 'false', "\n";
echo 'chain_has_inner=', (str_contains($chain, 'inner')) ? 'true' : 'false', "\n";
echo 'chain_has_outer=', (str_contains($chain, 'outer')) ? 'true' : 'false', "\n";
--EXPECT--
previous_is_exception=true
previous_message=inner
inner_previous_null=true
chain_has_next=true
chain_has_inner=true
chain_has_outer=true
