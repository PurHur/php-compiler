<?php

declare(strict_types=1);

$doc = new DOMDocument();

if (!property_exists($doc, 'preserveWhiteSpace')) {
    echo "fail: missing preserveWhiteSpace\n";
    exit(1);
}
if ($doc->preserveWhiteSpace != true) {
    echo "fail: preserveWhiteSpace default\n";
    exit(1);
}
if (!property_exists($doc, 'strictErrorChecking')) {
    echo "fail: missing strictErrorChecking\n";
    exit(1);
}
if ($doc->strictErrorChecking != true) {
    echo "fail: strictErrorChecking default\n";
    exit(1);
}

foreach ([
    'validateOnParse' => false,
    'resolveExternals' => false,
    'substituteEntities' => false,
    'recover' => false,
    'formatOutput' => false,
] as $prop => $expected) {
    if (!property_exists($doc, $prop)) {
        echo "fail: missing property {$prop}\n";
        exit(1);
    }
    $actual = $doc->{$prop};
    if ($actual != $expected) {
        echo "fail: {$prop} default expected ".var_export($expected, true).', got '.var_export($actual, true)."\n";
        exit(1);
    }
}

if (!property_exists($doc, 'encoding') || null !== $doc->encoding) {
    echo "fail: encoding default\n";
    exit(1);
}
if (!property_exists($doc, 'xmlVersion') || '1.0' !== $doc->xmlVersion) {
    echo "fail: xmlVersion default\n";
    exit(1);
}
if (!property_exists($doc, 'xmlStandalone') || true == $doc->xmlStandalone) {
    echo "fail: xmlStandalone default\n";
    exit(1);
}

$doc->validateOnParse = true;
if (!$doc->validateOnParse) {
    echo "fail: validateOnParse round-trip\n";
    exit(1);
}
$doc->validateOnParse = false;
if ($doc->validateOnParse) {
    echo "fail: validateOnParse assign false\n";
    exit(1);
}

echo "ok\n";
