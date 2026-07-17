--TEST--
DOMDocument::schemaValidateSource() invalid doc — libxml_get_errors under use_internal_errors (#20181, ext/dom/document.c)
--FILE--
<?php
declare(strict_types=1);

$xsd = <<<'XSD'
<?xml version="1.0"?>
<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema">
  <xs:element name="r">
    <xs:complexType>
      <xs:sequence>
        <xs:element name="a" type="xs:string"/>
      </xs:sequence>
    </xs:complexType>
  </xs:element>
</xs:schema>
XSD;

$doc = new DOMDocument();
$doc->loadXML('<r><b/></r>');

libxml_use_internal_errors(true);
libxml_clear_errors();

$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;
    return true;
});

$ok = $doc->schemaValidateSource($xsd);
restore_error_handler();

$errors = libxml_get_errors();
echo 'ok=' . ($ok ? '1' : '0') . "\n";
echo 'warnings=' . count($warnings) . "\n";
echo 'libxml=' . count($errors) . "\n";
if (isset($errors[0])) {
    echo 'msg=' . trim($errors[0]->message) . "\n";
    echo 'code=' . $errors[0]->code . "\n";
    echo 'level=' . $errors[0]->level . "\n";
}
?>
--EXPECT--
ok=0
warnings=0
libxml=1
msg=Element 'b': This element is not expected. Expected is ( a ).
code=1871
level=2
