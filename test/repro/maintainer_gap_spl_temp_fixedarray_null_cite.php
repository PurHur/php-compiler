<?php
/**
 * Maintainer gap #31807: SplTempFileObject / SplFixedArray::setSize null DEP cite #1.
 */
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $str): bool {
    $name = E_DEPRECATED === $no ? 'E_DEPRECATED' : (string) $no;
    echo "ERR:$name:$str\n";
    return true;
});

new SplTempFileObject(null);
echo "temp ok\n";
$a = new SplFixedArray(3);
$a->setSize(null);
echo 'size=' . $a->getSize() . "\n";
