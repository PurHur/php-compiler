<?php
// AOT: SplFileInfo absolute single-segment construct parity (#33304)
foreach (['/etc', '/', '/a/', '/etc/passwd', 'rel', 'foo/bar'] as $p) {
    $f = new SplFileInfo($p);
    echo json_encode([$p, $f->getPath(), $f->getFilename(), $f->getPathname()]), "\n";
}
$pi = (new SplFileInfo('/etc/passwd'))->getPathInfo();
echo 'pathinfo ', json_encode([$pi->getPath(), $pi->getFilename(), $pi->getPathname()]), "\n";
