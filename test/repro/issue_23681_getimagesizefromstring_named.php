<?php
/** Repro for #23681 — getimagesizefromstring Reflection + Zend named string/image_info. */
$r = new ReflectionFunction('getimagesizefromstring');
$bits = [];
foreach ($r->getParameters() as $p) {
    $bits[] = $p->getName() . ($p->isPassedByReference() ? '&' : '') . ($p->isOptional() ? '=' : '');
}
echo 'names=', implode(',', $bits), ' arity=', $r->getNumberOfParameters(),
    ' required=', $r->getNumberOfRequiredParameters(), "\n";

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

try {
    $sz = getimagesizefromstring(string: $png);
    echo 'named=', ($sz[0] ?? 'x'), 'x', ($sz[1] ?? 'x'), ' ', ($sz['mime'] ?? 'x'), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

$image_info = ['seed' => 1];
try {
    $sz = getimagesizefromstring(string: $png, image_info: $image_info);
    echo 'named_info=', ($sz[0] ?? 'x'), 'x', ($sz[1] ?? 'x'),
        ' written=', (is_array($image_info) && !isset($image_info['seed'])) ? 'yes' : 'no', "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

$pos = getimagesizefromstring($png);
echo 'pos=', ($pos[0] ?? 'x'), 'x', ($pos[1] ?? 'x'), "\n";

try {
    getimagesizefromstring(data: $png);
    echo "legacy_data=accepted\n";
} catch (Throwable $e) {
    echo 'legacy_data=', get_class($e), ':', $e->getMessage(), "\n";
}

try {
    $info = [];
    getimagesizefromstring(string: $png, info: $info);
    echo "legacy_info=accepted\n";
} catch (Throwable $e) {
    echo 'legacy_info=', get_class($e), ':', $e->getMessage(), "\n";
}

try {
    var_export(@getimagesizefromstring(string: 'x'));
    echo "\n";
} catch (Throwable $e) {
    echo 'invalid=', get_class($e), ':', $e->getMessage(), "\n";
}
