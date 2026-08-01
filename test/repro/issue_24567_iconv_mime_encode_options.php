<?php
// Repro #24567 — iconv_mime_encode Zend stub options (not preference)
$names = [];
foreach ((new ReflectionFunction('iconv_mime_encode'))->getParameters() as $p) {
    $names[] = $p->getName();
}

$opts = ['scheme' => 'Q', 'input-charset' => 'UTF-8', 'output-charset' => 'UTF-8'];
$named = iconv_mime_encode(field_name: 'Subject', field_value: 'test', options: $opts);
$positional = iconv_mime_encode('Subject', 'test', $opts);

$preferenceRejected = false;
try {
    iconv_mime_encode(field_name: 'Subject', field_value: 'test', preference: $opts);
} catch (Error $e) {
    $preferenceRejected = str_contains($e->getMessage(), 'preference');
}

$ok = ['field_name', 'field_value', 'options'] === $names
    && is_string($named)
    && str_starts_with($named, 'Subject:')
    && $named === $positional
    && $preferenceRejected;
echo $ok ? "ok\n" : "fail\n";
