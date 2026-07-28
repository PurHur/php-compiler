<?php
$td = sys_get_temp_dir() . '/rdi_dot_' . getmypid();
@mkdir($td);
@mkdir($td . '/sub');
file_put_contents($td . '/f.txt', 'x');

$it = new RecursiveDirectoryIterator($td, FilesystemIterator::CURRENT_AS_SELF);
$seen = [];
foreach ($it as $entry) {
    $name = $entry->getFilename();
    $seen[$name] = $entry->hasChildren();
}
ksort($seen);
foreach ($seen as $name => $has) {
    echo $name, '=', $has ? '1' : '0', "\n";
}

@unlink($td . '/f.txt');
@rmdir($td . '/sub');
@rmdir($td);
