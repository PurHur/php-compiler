<?php
/** Repro for #20387 — ZipArchive unchange, replaceFile, addGlob, addPattern */
$need = [
    'unchangeArchive',
    'unchangeAll',
    'unchangeName',
    'unchangeIndex',
    'replaceFile',
    'addGlob',
    'addPattern',
];
foreach ($need as $m) {
    echo $m, '=', method_exists(ZipArchive::class, $m) ? 'yes' : 'no', "\n";
}
