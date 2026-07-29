<?php
/** Repro for #23343 — getimagesize Reflection + Zend named filename/image_info. */
$r = new ReflectionFunction('getimagesize');
$bits = [];
foreach ($r->getParameters() as $p) {
    $bits[] = $p->getName() . ($p->isPassedByReference() ? '&' : '') . ($p->isOptional() ? '=' : '');
}
echo 'names=', implode(',', $bits), ' arity=', $r->getNumberOfParameters(),
    ' required=', $r->getNumberOfRequiredParameters(), "\n";

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$path = sys_get_temp_dir() . '/phpc-issue-23343-' . getmypid() . '.png';
file_put_contents($path, $png);

try {
    $sz = getimagesize(filename: $path);
    echo 'named=', ($sz[0] ?? 'x'), 'x', ($sz[1] ?? 'x'), ' ', ($sz['mime'] ?? 'x'), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

$image_info = ['seed' => 1];
try {
    $sz = getimagesize(filename: $path, image_info: $image_info);
    echo 'named_info=', ($sz[0] ?? 'x'), 'x', ($sz[1] ?? 'x'),
        ' written=', (is_array($image_info) && !isset($image_info['seed'])) ? 'yes' : 'no', "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

$pos = getimagesize($path);
echo 'pos=', ($pos[0] ?? 'x'), 'x', ($pos[1] ?? 'x'), "\n";

try {
    getimagesize(imagefile: $path);
    echo "legacy_imagefile=accepted\n";
} catch (Throwable $e) {
    echo 'legacy_imagefile=', get_class($e), ':', $e->getMessage(), "\n";
}

@unlink($path);
