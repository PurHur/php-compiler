<?php
// #34818 — AOT ternary true ? intCall/userFn : string-literal must match Zend.
// Peer of #34814 (string Internal SIGSEGV). Root cause: ?: phi Temporary shared a
// scope slot with FUNCCALL name Literal; bindScopeSlot now displaces those Literals.
// (crc32() itself is wrong under AOT even without ternary — not this ticket.)

echo true ? strlen('abc') : 'bad', "\n";
echo true ? ord('A') : 'bad', "\n";
function f(string $s): string
{
    return strtoupper($s);
}
echo true ? f('ab') : 'bad', "\n";
