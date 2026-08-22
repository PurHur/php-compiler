<?php
// AOT (array) cast — PHI type/predecessor + value-box kind mask (#33863)
echo implode(',', (array)[1, 2]), "\n";
echo '[', implode(',', (array) null), ']', "\n";
echo implode(',', (array) 7), "\n";
class C33863
{
    public int $x = 1;
    public string $y = 'hi';
}
$keys = array_keys((array) new C33863());
sort($keys);
echo implode(',', $keys), "\n";
$ao = new ArrayObject([9, 8]);
echo implode(',', (array) $ao->getArrayCopy()), "\n";
