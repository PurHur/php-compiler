<?php
/**
 * #32431 — string⊙string bitwise is byte-wise (zend bitwise_*_function).
 * Leftover of #32407 (native long ⊙ numeric-string).
 *
 * Runtime returns (not literals) so AOT must emit the byte loop rather than
 * const-fold. Assert with ord()/var_dump: bin2hex() of a 0x03 byte SIGSEGVs
 * in NestedJIT (separate helper bug) and is not this opcode.
 */
function s(string $x): string
{
    return $x;
}
echo ord(s('a') ^ s('b')), PHP_EOL;
var_dump(s('AB') & s('A'));
var_dump(s('A') | s('BC'));
echo ord(s('AB') ^ s('C')), PHP_EOL;
var_dump(s('') | s('x'));
var_dump(s('xy') & s(''));
var_dump(s('7') & s('3'));
