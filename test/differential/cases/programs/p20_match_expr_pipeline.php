<?php
// #36221 program: match expression dispatch pipeline
function grade(int $score): string {
    return match (true) {
        $score >= 90 => 'A',
        $score >= 80 => 'B',
        $score >= 70 => 'C',
        $score >= 60 => 'D',
        default => 'F',
    };
}
function verb(string $op, int $a, int $b): int {
    return match ($op) {
        'add' => $a + $b,
        'sub' => $a - $b,
        'mul' => $a * $b,
        'div' => intdiv($a, $b),
        default => throw new InvalidArgumentException($op),
    };
}
$scores = [95, 82, 70, 60, 40];
$g = [];
foreach ($scores as $s) { $g[] = $s . '=' . grade($s); }
$ops = [
    verb('add', 3, 4),
    verb('sub', 10, 3),
    verb('mul', 6, 7),
    verb('div', 20, 4),
];
try {
    verb('nope', 1, 1);
    $err = 'none';
} catch (InvalidArgumentException $e) {
    $err = $e->getMessage();
}
$out = implode(',', $g) . '|ops=' . implode(',', $ops) . '|err=' . $err . "\n";
echo $out;
echo 'checksum=', strlen($out), ':', sprintf('%u', crc32($out)), "\n";
