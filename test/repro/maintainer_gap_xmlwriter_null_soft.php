<?php
// XMLWriter string args: soft-null DEP then empty-name ValueError / content ok (php-src php_xmlwriter.c).
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
