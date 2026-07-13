--TEST--
SPL SplFileObject::current() after rewind — empty string at BOF not false (#18429, ext/spl/spl_directory.c)
--FILE--
<?php
declare(strict_types=1);
$f = new SplFileObject('php://memory');
$f->fwrite("line1\nline2\n");
$f->rewind();
$valid = $f->valid();
$current = $f->current();
echo 'valid='.var_export($valid, true)."\n";
echo 'current_type='.(\is_string($current) ? 'string' : gettype($current))."\n";
echo 'match='.('' === $current ? 'true' : 'false')."\n";
--EXPECT--
valid=true
current_type=string
match=true
