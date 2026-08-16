--TEST--
XMLWriter string args (null) soft-null DEP then empty-name ValueError / content ok (#31610, ext/xmlwriter/php_xmlwriter.c)
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo "DEP:{$msg}\n";
        return true;
    }
    echo "E{$no}:{$msg}\n";
    return true;
});

function run(string $label, callable $fn): void
{
    echo "== {$label} ==\n";
    try {
        $fn();
        echo "ok\n";
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}

run('startElement(null)', static function (): void {
    $w = new XMLWriter();
    $w->openMemory();
    $w->startElement(null);
});

run('writeElement(null)', static function (): void {
    $w = new XMLWriter();
    $w->openMemory();
    $w->startDocument();
    $w->writeElement(null, 'x');
});

run('startAttribute(null)', static function (): void {
    $w = new XMLWriter();
    $w->openMemory();
    $w->startDocument();
    $w->startElement('r');
    $w->startAttribute(null);
});

run('writeAttribute(null)', static function (): void {
    $w = new XMLWriter();
    $w->openMemory();
    $w->startDocument();
    $w->startElement('r');
    $w->writeAttribute(null, 'v');
});

run('text(null)', static function (): void {
    $w = new XMLWriter();
    $w->openMemory();
    $w->startDocument();
    $w->startElement('r');
    $w->text(null);
});

run('writeComment(null)', static function (): void {
    $w = new XMLWriter();
    $w->openMemory();
    $w->writeComment(null);
});

run('writeCdata(null)', static function (): void {
    $w = new XMLWriter();
    $w->openMemory();
    $w->startDocument();
    $w->startElement('r');
    $w->writeCdata(null);
});

run('writeRaw(null)', static function (): void {
    $w = new XMLWriter();
    $w->openMemory();
    $w->writeRaw(null);
});

run('setIndentString(null)', static function (): void {
    $w = new XMLWriter();
    $w->openMemory();
    $w->setIndentString(null);
});
?>
--EXPECT--
== startElement(null) ==
DEP:XMLWriter::startElement(): Passing null to parameter #1 ($name) of type string is deprecated
ValueError: XMLWriter::startElement(): Argument #2 must be a valid element name, "" given
== writeElement(null) ==
DEP:XMLWriter::writeElement(): Passing null to parameter #1 ($name) of type string is deprecated
ValueError: XMLWriter::writeElement(): Argument #2 ($content) must be a valid element name, "" given
== startAttribute(null) ==
DEP:XMLWriter::startAttribute(): Passing null to parameter #1 ($name) of type string is deprecated
ValueError: XMLWriter::startAttribute(): Argument #2 must be a valid attribute name, "" given
== writeAttribute(null) ==
DEP:XMLWriter::writeAttribute(): Passing null to parameter #1 ($name) of type string is deprecated
ValueError: XMLWriter::writeAttribute(): Argument #2 ($value) must be a valid attribute name, "" given
== text(null) ==
DEP:XMLWriter::text(): Passing null to parameter #1 ($content) of type string is deprecated
ok
== writeComment(null) ==
DEP:XMLWriter::writeComment(): Passing null to parameter #1 ($content) of type string is deprecated
ok
== writeCdata(null) ==
DEP:XMLWriter::writeCdata(): Passing null to parameter #1 ($content) of type string is deprecated
ok
== writeRaw(null) ==
DEP:XMLWriter::writeRaw(): Passing null to parameter #1 ($content) of type string is deprecated
ok
== setIndentString(null) ==
DEP:XMLWriter::setIndentString(): Passing null to parameter #1 ($indentation) of type string is deprecated
ok
