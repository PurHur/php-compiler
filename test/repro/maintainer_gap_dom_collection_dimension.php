<?php

declare(strict_types=1);

$doc = new DOMDocument();
$doc->loadXML('<root a="1" b="2"><child/><sib/></root>');
$list = $doc->getElementsByTagName('*');
$map = $doc->documentElement->attributes;

if (!isset($list[0]) || $list[0]->nodeName !== 'root') {
    fwrite(STDERR, "fail: DOMNodeList int dim\n");
    exit(1);
}
if ($list[9] !== null) {
    fwrite(STDERR, "fail: DOMNodeList OOB must be null\n");
    exit(1);
}
if ($list['foo'] !== null) {
    fwrite(STDERR, "fail: DOMNodeList named dim must be null\n");
    exit(1);
}
if (!isset($map['a']) || $map['a']->value !== '1') {
    fwrite(STDERR, "fail: DOMNamedNodeMap string dim\n");
    exit(1);
}
if (!isset($map[0]) || $map[0]->name !== 'a') {
    fwrite(STDERR, "fail: DOMNamedNodeMap int dim\n");
    exit(1);
}
if ($map['z'] !== null) {
    fwrite(STDERR, "fail: DOMNamedNodeMap missing must be null\n");
    exit(1);
}
if ($list instanceof ArrayAccess || $map instanceof ArrayAccess) {
    fwrite(STDERR, "fail: collections must not implement ArrayAccess\n");
    exit(1);
}
if (empty($list[0]) || !empty($list[9])) {
    fwrite(STDERR, "fail: empty() has_dimension parity\n");
    exit(1);
}

$writeOk = false;
try {
    $list[0] = 1;
    $writeOk = true;
} catch (Error $e) {
    if ($e->getMessage() !== 'Cannot use object of type DOMNodeList as array') {
        fwrite(STDERR, "fail: write message: ".$e->getMessage()."\n");
        exit(1);
    }
}
if ($writeOk) {
    fwrite(STDERR, "fail: write must Error\n");
    exit(1);
}

$unsetListOk = false;
try {
    unset($list[0]);
    $unsetListOk = true;
} catch (Error $e) {
    if ($e->getMessage() !== 'Cannot use object of type DOMNodeList as array') {
        fwrite(STDERR, "fail: unset list message: ".$e->getMessage()."\n");
        exit(1);
    }
}
if ($unsetListOk) {
    fwrite(STDERR, "fail: unset(DOMNodeList) must Error\n");
    exit(1);
}

$unsetMapOk = false;
try {
    unset($map['a']);
    $unsetMapOk = true;
} catch (Error $e) {
    if ($e->getMessage() !== 'Cannot use object of type DOMNamedNodeMap as array') {
        fwrite(STDERR, "fail: unset map message: ".$e->getMessage()."\n");
        exit(1);
    }
}
if ($unsetMapOk) {
    fwrite(STDERR, "fail: unset(DOMNamedNodeMap) must Error\n");
    exit(1);
}

$negOk = false;
try {
    $map[-1];
    $negOk = true;
} catch (ValueError $e) {
    if ($e->getMessage() !== 'must be between 0 and 2147483647') {
        fwrite(STDERR, "fail: ValueError message: ".$e->getMessage()."\n");
        exit(1);
    }
}
if ($negOk) {
    fwrite(STDERR, "fail: NamedNodeMap[-1] must ValueError\n");
    exit(1);
}

echo "ok\n";
