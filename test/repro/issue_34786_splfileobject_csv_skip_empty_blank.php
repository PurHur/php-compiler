<?php
/**
 * #34786 — READ_CSV|SKIP_EMPTY without DROP_NEW_LINE must keep mid-file blank as [null].
 * With DROP_NEW_LINE, php-src is_line_empty skips the blank (ext/spl/spl_directory.c).
 */
$cases = [
    'csv+skip' => SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY,
    'csv+skip+drop' => SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE,
];
foreach ($cases as $label => $flags) {
    echo $label, ":\n";
    $f = new SplTempFileObject();
    $f->fwrite("a,1\n\nb,2\n");
    $f->rewind();
    $f->setFlags($flags);
    foreach ($f as $row) {
        var_export($row);
        echo "\n";
    }
}
