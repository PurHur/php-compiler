--TEST--
DOMDocument::loadXML accepts comment and PI before root (#19361)
--FILE--
<?php
foreach ([
  'comment' => '<!--c--><root/>',
  'pi' => '<?pi target data?><root/>',
] as $label => $xml) {
  $dom = new DOMDocument();
  $ok = @$dom->loadXML($xml);
  echo $label, ': ok=', var_export($ok, true), ' children=', $dom->childNodes->length, "\n";
  foreach ($dom->childNodes as $n) {
    echo '  ', $n->nodeName, ':', $n->nodeType;
    if ($n instanceof DOMComment) {
      echo ' data=', $n->data;
    }
    if ($n instanceof DOMProcessingInstruction) {
      echo ' data=', $n->data;
    }
    echo "\n";
  }
}
--EXPECT--
comment: ok=true children=2
  #comment:8 data=c
  root:1
pi: ok=true children=2
  pi:7 data=target data
  root:1
