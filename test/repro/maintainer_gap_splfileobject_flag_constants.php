<?php
declare(strict_types=1);

$missing = [];
foreach (['READ_AHEAD', 'SKIP_EMPTY', 'DROP_NEW_LINE', 'READ_CSV'] as $name) {
    if (!\defined('SplFileObject::'.$name)) {
        $missing[] = $name;
    }
}
if ([] !== $missing) {
    fwrite(STDERR, 'FAIL: missing SplFileObject constants: '.implode(', ', $missing)."\n");
    exit(1);
}

$expected = [
    'READ_AHEAD' => 2,
    'SKIP_EMPTY' => 4,
    'DROP_NEW_LINE' => 1,
    'READ_CSV' => 8,
];
foreach ($expected as $name => $value) {
    $actual = \constant('SplFileObject::'.$name);
    if ($actual !== $value) {
        fwrite(STDERR, "FAIL: SplFileObject::$name = $actual, expected $value\n");
        exit(1);
    }
}

$tmp = sys_get_temp_dir().'/splfileobject_flags_'.getmypid().'.txt';
file_put_contents($tmp, "line1\n\nline3\n");
$fo = new SplTempFileObject();
$fo->setFlags(SplFileObject::READ_AHEAD | SplFileObject::SKIP_EMPTY);
if ($fo->getFlags() !== (SplFileObject::READ_AHEAD | SplFileObject::SKIP_EMPTY)) {
    fwrite(STDERR, 'FAIL: setFlags/getFlags mismatch: '.$fo->getFlags()."\n");
    exit(1);
}
@unlink($tmp);

echo "OK\n";
