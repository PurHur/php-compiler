--TEST--
Language: unset($s[$k]) Error getFile()/getLine() user site (#31883, zend_execute.c ZEND_UNSET_DIM)
--FILE--
<?php
function show(string $label, Throwable $e): void
{
    $f = $e->getFile();
    $fileOk = ($f !== '' && $f !== null && !str_contains((string) $f, 'ExceptionSupport'));
    echo $label,
        '|file=', $fileOk ? 'ok' : 'bad',
        '|line=', $e->getLine(),
        '|', get_class($e),
        "\n";
}

$s = 'abc';
try {
    unset($s[1]);
} catch (Throwable $e) {
    show('unset-str-offset', $e);
}

$x = 1;
try {
    unset($x[0]);
} catch (Throwable $e) {
    show('unset-scalar-offset', $e);
}

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
--EXPECT--
unset-str-offset|file=ok|line=15|Error
unset-scalar-offset|file=ok|line=22|Error
readonly-write|file=ok|line=33|Error
