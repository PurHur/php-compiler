<?php
/**
 * Maintainer gap: SplFileObject::setFlags(null).
 * Zend: E_DEPRECATED + soft-coerce to 0
 * VM: silent coerce to 0 (no E_DEPRECATED)
 */
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo "DEP:{$msg}\n";
        return true;
    }
    echo "E{$no}:{$msg}\n";
    return true;
});
$tmp = tempnam(sys_get_temp_dir(), 'sfo317');
file_put_contents($tmp, "a\nb\n");
try {
    $f = new SplFileObject($tmp);
    $f->setFlags(null);
    echo 'flags=' . $f->getFlags() . "\n";
} catch (Throwable $e) {
    echo get_class($e) . ':' . $e->getMessage() . "\n";
}
@unlink($tmp);
