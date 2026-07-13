<?php
declare(strict_types=1);

// #18429 — SplFileObject::current() at rewound BOF must be string '' not bool false.
$f = new SplFileObject('php://memory');
$f->fwrite("line1\nline2\n");
$f->rewind();
$valid = $f->valid();
$current = $f->current();
$typeOk = \is_string($current);
$match = '' === $current;
echo 'valid='.var_export($valid, true)."\n";
echo 'current_type='.(\is_string($current) ? 'string' : gettype($current))."\n";
echo 'match='.($match ? 'true' : 'false')."\n";
exit($valid && $typeOk && $match ? 0 : 1);
