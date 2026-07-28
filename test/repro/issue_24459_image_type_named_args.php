<?php
/** Repro for #24459 — image_type_to_* Reflection image_type + named args. */
$r1 = new ReflectionFunction('image_type_to_extension');
$r2 = new ReflectionFunction('image_type_to_mime_type');
echo implode(',', array_map(static fn ($p) => $p->getName(), $r1->getParameters())), "\n";
echo implode(',', array_map(static fn ($p) => $p->getName(), $r2->getParameters())), "\n";
try {
    echo image_type_to_extension(image_type: IMAGETYPE_PNG), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    echo image_type_to_mime_type(image_type: IMAGETYPE_PNG), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    image_type_to_extension(imagetype: IMAGETYPE_PNG);
    echo "legacy accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
