<?php
/**
 * Nested `new` as first constructor argument + second mode arg (#19439).
 * Zend: php test/repro/maintainer_gap_nested_new_rii_mode.php
 * VM:   php bin/vm.php test/repro/maintainer_gap_nested_new_rii_mode.php
 */
declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/nested_rii_' . uniqid('', true);
mkdir($tmp);
file_put_contents($tmp . '/a.txt', 'x');
mkdir($tmp . '/sub');
file_put_contents($tmp . '/sub/b.txt', 'y');

$fail = false;

echo "literal-mode:\n";
try {
    $namesLiteral = [];
    foreach (
        new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
            1
        ) as $fLiteral
    ) {
        $namesLiteral[] = $fLiteral->getFilename();
    }
    sort($namesLiteral);
    echo json_encode($namesLiteral), "\n";
    if ($namesLiteral !== ['a.txt', 'b.txt', 'sub']) {
        $fail = true;
    }
} catch (Throwable $e) {
    echo 'ERR ', get_class($e), ': ', $e->getMessage(), "\n";
    $fail = true;
}

echo "const-mode:\n";
$namesConst = [];
foreach (
    new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    ) as $fConst
) {
    $namesConst[] = $fConst->getFilename();
}
sort($namesConst);
echo json_encode($namesConst), "\n";
if ($namesConst !== ['a.txt', 'b.txt', 'sub']) {
    $fail = true;
}

@unlink($tmp . '/a.txt');
@unlink($tmp . '/sub/b.txt');
@rmdir($tmp . '/sub');
@rmdir($tmp);

echo $fail ? "fail\n" : "ok\n";
exit($fail ? 1 : 0);
