--TEST--
types: true/false return TypeError includes callable name (#25635)
--FILE--
<?php
function badTrue(): true { return false; }
try {
    badTrue();
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

class C25635 {
    public function badFalse(): false { return true; }
}
try {
    (new C25635)->badFalse();
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

function badInt(): int { return []; }
try {
    badInt();
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
badTrue(): Return value must be of type true, false returned
C25635::badFalse(): Return value must be of type false, true returned
badInt(): Return value must be of type int, array returned
