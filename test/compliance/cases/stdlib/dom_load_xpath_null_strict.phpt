--TEST--
DOMDocument loadXML/loadHTML + DOMXPath query/evaluate null TypeError under strict_types (#30041)
--FILE--
<?php
declare(strict_types=1);
$d = new DOMDocument();
$d->loadXML('<r/>');
$xp = new DOMXPath($d);
$cases = [
    ['loadXML', static function () {
        $doc = new DOMDocument();
        return $doc->loadXML(null);
    }],
    ['loadHTML', static function () {
        $doc = new DOMDocument();
        return $doc->loadHTML(null);
    }],
    ['query', static fn () => $xp->query(null)],
    ['evaluate', static fn () => $xp->evaluate(null)],
];
foreach ($cases as [$name, $fn]) {
    try {
        $fn();
        echo $name, "=fail\n";
    } catch (Throwable $e) {
        echo $name, '=', get_class($e), ':', $e->getMessage(), "\n";
    }
}
--EXPECT--
loadXML=TypeError:DOMDocument::loadXML(): Argument #1 ($source) must be of type string, null given
loadHTML=TypeError:DOMDocument::loadHTML(): Argument #1 ($source) must be of type string, null given
query=TypeError:DOMXPath::query(): Argument #1 ($expression) must be of type string, null given
evaluate=TypeError:DOMXPath::evaluate(): Argument #1 ($expression) must be of type string, null given
