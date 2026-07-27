<?php
// #23986 — ternary / && must reuse PropertyFetch as a read (not write lvalue).

class R {
    public readonly string $x;
    public function __construct(string $x) {
        $this->x = $x;
    }
}
$r = new R('hi');
try {
    echo ($r->x ? $r->x : 'no'), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    echo (($r->x && strlen($r->x)) ? 'y' : 'n'), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo ($r->x ?: 'no'), "\n";

class M {
    private $d = ['x' => 'hi'];
    public function __get($k) {
        return $this->d[$k] ?? null;
    }
    public function __isset($k) {
        return isset($this->d[$k]);
    }
}
$m = new M();
var_export(($m->x ? $m->x : 'no'));
echo "\n";
var_export($m->x ?: 'no');
echo "\n";

$doc = new DOMDocument();
$doc->loadXML('<a><b/></a>');
$doc->documentElement->appendChild($doc->createElement('c'));
$doc->documentElement->removeChild($doc->documentElement->firstChild);
try {
    echo ($doc->documentElement->firstChild
        ? $doc->documentElement->firstChild->nodeName
        : 'null'), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
$fc = $doc->documentElement->firstChild;
echo $fc->nodeName, "\n";
