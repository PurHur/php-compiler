<?php
/** Maintainer gap: XMLReader::read() malformed XML warnings (#19933). */
$warnings = [];
set_error_handler(static function (int $n, string $m) use (&$warnings): bool {
    $warnings[] = $m;

    return true;
});
$r = XMLReader::XML('<not-closed>');
while ($r->read()) {
}
echo count($warnings), "\n";
foreach ($warnings as $w) {
    echo $w, "\n";
}
