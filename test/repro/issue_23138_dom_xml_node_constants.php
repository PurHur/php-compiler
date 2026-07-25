<?php
// Repro for #23138 — XML_*_NODE + DOM_PHP_ERR globals (php-src ext/dom/php_dom.c).
// Bare names so AOT matches VM (variable constant() is a separate AOT gap).
$ok = 1;
$got = [
    'XML_ELEMENT_NODE' => defined('XML_ELEMENT_NODE') ? XML_ELEMENT_NODE : null,
    'XML_ATTRIBUTE_NODE' => defined('XML_ATTRIBUTE_NODE') ? XML_ATTRIBUTE_NODE : null,
    'XML_TEXT_NODE' => defined('XML_TEXT_NODE') ? XML_TEXT_NODE : null,
    'XML_PI_NODE' => defined('XML_PI_NODE') ? XML_PI_NODE : null,
    'XML_NAMESPACE_DECL_NODE' => defined('XML_NAMESPACE_DECL_NODE') ? XML_NAMESPACE_DECL_NODE : null,
    'XML_LOCAL_NAMESPACE' => defined('XML_LOCAL_NAMESPACE') ? XML_LOCAL_NAMESPACE : null,
    'DOM_PHP_ERR' => defined('DOM_PHP_ERR') ? DOM_PHP_ERR : null,
];
$want = [
    'XML_ELEMENT_NODE' => 1,
    'XML_ATTRIBUTE_NODE' => 2,
    'XML_TEXT_NODE' => 3,
    'XML_PI_NODE' => 7,
    'XML_NAMESPACE_DECL_NODE' => 18,
    'XML_LOCAL_NAMESPACE' => 18,
    'DOM_PHP_ERR' => 0,
];
foreach ($want as $name => $v) {
    if (null === $got[$name] || $got[$name] !== $v) {
        $ok = 0;
        echo 'bad=', $name, "\n";
    }
}
echo 'ok=', $ok, "\n";
echo 'element=', XML_ELEMENT_NODE, ' pi=', XML_PI_NODE, ' php_err=', DOM_PHP_ERR, "\n";
