<?php
// Caught Error from unset($string[$k]) must stamp user getFile()/getLine() like Zend.
error_reporting(E_ALL);

function show(string $label, Throwable $e): void
{
    $f = $e->getFile();
    echo $label,
        '|file=', ($f === '' || $f === null) ? '(empty)' : basename($f),
        '|line=', $e->getLine(),
        '|', get_class($e),
        '|', $e->getMessage(),
        "\n";
}

$s = 'abc';
try {
    unset($s[1]);
} catch (Throwable $e) {
    show('unset-str-offset', $e);
}

// Control: readonly write already stamps user file (must stay green).
class T5 {
    public readonly int $x;
    public function __construct(int $x) { $this->x = $x; }
}
$o5 = new T5(1);
try {
    $o5->x = 2;
} catch (Throwable $e) {
    show('readonly-write', $e);
}
