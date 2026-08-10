<?php
/**
 * Repro #29799 — loadHTML opaque text (script/style/textarea/…).
 * Zend/libxml keeps `<` as text until the matching end tag.
 */
$flags = LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD;

$cases = [
    'script_lt' => '<script>if (a < b) {}</script>',
    'script_ent' => '<script>a &lt; b &amp; c</script>',
    'style_lt' => '<style>a < b {}</style>',
    'textarea_lt' => '<textarea>a < b</textarea>',
    'title_lt' => '<title>a < b</title>',
    'iframe_lt' => '<iframe>a < b</iframe>',
];

foreach ($cases as $name => $html) {
    $d = new DOMDocument();
    @$d->loadHTML($html, $flags);
    $root = $d->documentElement;
    echo $name, ' text=';
    var_export(null === $root ? null : $root->textContent);
    echo ' save=';
    var_export(null === $root ? null : trim($d->saveHTML($root)));
    echo "\n";
}
