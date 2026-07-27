--TEST--
stdlib sscanf() defines unbound by-ref outs as null (#23567, ext/standard/sscanf.c)
--FILE--
<?php
declare(strict_types=1);

$n = sscanf('a:1', '%s:%d', $s, $i);
echo "n=$n\n";
echo "s=", var_export($s, true), "\n";
echo "exists_i=", array_key_exists('i', get_defined_vars()) ? '1' : '0', "\n";
echo "i_null=", (array_key_exists('i', get_defined_vars()) && null === $i) ? '1' : '0', "\n";
--EXPECT--
n=1
s='a:1'
exists_i=1
i_null=1
