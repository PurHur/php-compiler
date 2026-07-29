--TEST--
getimagesize Reflection filename/image_info + named args (VM, issue #23343)
--FILE--
<?php
$r = new ReflectionFunction('getimagesize');
$bits = [];
foreach ($r->getParameters() as $p) {
    $bits[] = $p->getName() . ($p->isPassedByReference() ? '&' : '') . ($p->isOptional() ? '=' : '');
}
echo implode(',', $bits), PHP_EOL;
echo 'arity=', $r->getNumberOfParameters(), ' required=', $r->getNumberOfRequiredParameters(), PHP_EOL;

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$path = sys_get_temp_dir() . '/phpc-getimagesize-named-' . getmypid() . '.png';
file_put_contents($path, $png);

$fromNamed = getimagesize(filename: $path);
echo ($fromNamed[0] ?? 'x') . 'x' . ($fromNamed[1] ?? 'x') . ' ' . ($fromNamed['mime'] ?? 'x'), PHP_EOL;

$image_info = ['seed' => 1];
$fromNamedInfo = getimagesize(filename: $path, image_info: $image_info);
echo ($fromNamedInfo[0] ?? 'x') . 'x' . ($fromNamedInfo[1] ?? 'x'), PHP_EOL;
echo is_array($image_info) && !isset($image_info['seed']) ? 'image_info_written' : 'image_info_stale', PHP_EOL;

$pos = getimagesize($path);
echo ($pos[0] ?? 'x') . 'x' . ($pos[1] ?? 'x'), PHP_EOL;

try {
    getimagesize(imagefile: $path);
    echo "legacy imagefile accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
try {
    $info = [];
    getimagesize(filename: $path, info: $info);
    echo "legacy info accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}

@unlink($path);
--EXPECT--
filename,image_info&=
arity=2 required=1
1x1 image/png
1x1
image_info_written
1x1
Unknown named parameter $imagefile
Unknown named parameter $info
