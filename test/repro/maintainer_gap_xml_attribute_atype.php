<?php
// Repro for #24098 — libxml attribute-type (atype) globals from ext/dom.
// Bare names so AOT matches VM (variable constant() is a separate AOT gap).
$ok = 1;
$expect = [
    'XML_ATTRIBUTE_NODE' => 2,
    'XML_ATTRIBUTE_CDATA' => 1,
    'XML_ATTRIBUTE_ID' => 2,
    'XML_ATTRIBUTE_IDREF' => 3,
    'XML_ATTRIBUTE_IDREFS' => 4,
    'XML_ATTRIBUTE_ENTITY' => 6,
    'XML_ATTRIBUTE_NMTOKEN' => 7,
    'XML_ATTRIBUTE_NMTOKENS' => 8,
    'XML_ATTRIBUTE_ENUMERATION' => 9,
    'XML_ATTRIBUTE_NOTATION' => 10,
];
$got = [
    'XML_ATTRIBUTE_NODE' => defined('XML_ATTRIBUTE_NODE') ? XML_ATTRIBUTE_NODE : null,
    'XML_ATTRIBUTE_CDATA' => defined('XML_ATTRIBUTE_CDATA') ? XML_ATTRIBUTE_CDATA : null,
    'XML_ATTRIBUTE_ID' => defined('XML_ATTRIBUTE_ID') ? XML_ATTRIBUTE_ID : null,
    'XML_ATTRIBUTE_IDREF' => defined('XML_ATTRIBUTE_IDREF') ? XML_ATTRIBUTE_IDREF : null,
    'XML_ATTRIBUTE_IDREFS' => defined('XML_ATTRIBUTE_IDREFS') ? XML_ATTRIBUTE_IDREFS : null,
    'XML_ATTRIBUTE_ENTITY' => defined('XML_ATTRIBUTE_ENTITY') ? XML_ATTRIBUTE_ENTITY : null,
    'XML_ATTRIBUTE_NMTOKEN' => defined('XML_ATTRIBUTE_NMTOKEN') ? XML_ATTRIBUTE_NMTOKEN : null,
    'XML_ATTRIBUTE_NMTOKENS' => defined('XML_ATTRIBUTE_NMTOKENS') ? XML_ATTRIBUTE_NMTOKENS : null,
    'XML_ATTRIBUTE_ENUMERATION' => defined('XML_ATTRIBUTE_ENUMERATION') ? XML_ATTRIBUTE_ENUMERATION : null,
    'XML_ATTRIBUTE_NOTATION' => defined('XML_ATTRIBUTE_NOTATION') ? XML_ATTRIBUTE_NOTATION : null,
];
foreach ($expect as $name => $want) {
    if (null === $got[$name] || $got[$name] !== $want) {
        $ok = 0;
        echo 'bad=', $name, ' got=', null === $got[$name] ? 'UNDEF' : (string) $got[$name], "\n";
    }
}
$buckets = get_defined_constants(true);
$dom = $buckets['dom'] ?? [];
foreach ([
    'XML_ATTRIBUTE_CDATA',
    'XML_ATTRIBUTE_ID',
    'XML_ATTRIBUTE_IDREF',
    'XML_ATTRIBUTE_IDREFS',
    'XML_ATTRIBUTE_ENTITY',
    'XML_ATTRIBUTE_NMTOKEN',
    'XML_ATTRIBUTE_NMTOKENS',
    'XML_ATTRIBUTE_ENUMERATION',
    'XML_ATTRIBUTE_NOTATION',
] as $name) {
    if (!array_key_exists($name, $dom) || $dom[$name] !== $expect[$name]) {
        $ok = 0;
        echo 'bad_bucket=', $name, "\n";
    }
}
echo 'ok=', $ok, "\n";
echo 'cdata=', XML_ATTRIBUTE_CDATA, ' id=', XML_ATTRIBUTE_ID, ' entity=', XML_ATTRIBUTE_ENTITY, "\n";
