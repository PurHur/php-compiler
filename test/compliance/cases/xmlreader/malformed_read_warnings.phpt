--TEST--
xmlreader XMLReader::read() on malformed in-memory XML — libxml warnings (#19933, ext/xmlreader/php_xmlreader.c)
--FILE--
<?php
$warnings = [];
set_error_handler(static function (int $n, string $m) use (&$warnings): bool {
    $warnings[] = $m;

    return true;
});
$r = XMLReader::XML('<not-closed>');
while ($r->read()) {
}
echo count($warnings), "\n";
echo str_contains($warnings[0] ?? '', 'parser error : Extra content at the end of the document') ? "err1\n" : "noerr1\n";
echo ($warnings[1] ?? '') === 'XMLReader::read(): <not-closed>' ? "err2\n" : "noerr2\n";
echo str_ends_with($warnings[2] ?? '', '^') && str_contains($warnings[2] ?? '', '<not-closed>') === false ? "err3\n" : "noerr3\n";
$r2 = new XMLReader();
$r2->XML('<r><a>1</a></r>');
$names = [];
while ($r2->read()) {
    if ($r2->nodeType === XMLReader::ELEMENT) {
        $names[] = $r2->name;
    }
}
echo implode(':', $names), ":\n";
?>
--EXPECT--
3
err1
err2
err3
r:a:
