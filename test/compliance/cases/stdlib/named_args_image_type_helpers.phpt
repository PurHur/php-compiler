--TEST--
image_type_to_extension/mime_type Reflection image_type + named args (VM, issue #24459)
--FILE--
<?php
$r1 = new ReflectionFunction('image_type_to_extension');
$r2 = new ReflectionFunction('image_type_to_mime_type');
echo implode(',', array_map(static fn ($p) => $p->getName(), $r1->getParameters())), PHP_EOL;
echo implode(',', array_map(static fn ($p) => $p->getName(), $r2->getParameters())), PHP_EOL;
echo image_type_to_extension(image_type: IMAGETYPE_PNG), PHP_EOL;
echo image_type_to_mime_type(image_type: IMAGETYPE_PNG), PHP_EOL;
echo image_type_to_extension(IMAGETYPE_PNG), PHP_EOL;
try {
    image_type_to_extension(imagetype: IMAGETYPE_PNG);
    echo "legacy accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
try {
    image_type_to_mime_type(imagetype: IMAGETYPE_PNG);
    echo "legacy accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
--EXPECT--
image_type,include_dot
image_type
.png
image/png
.png
Unknown named parameter $imagetype
Unknown named parameter $imagetype
