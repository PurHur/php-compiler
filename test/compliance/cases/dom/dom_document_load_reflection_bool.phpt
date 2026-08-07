--TEST--
DOMDocument::load/loadXML/loadHTML/loadHTMLFile Reflection bool + options int (#28713, ext/dom/php_dom.stub.php)
--FILE--
<?php
declare(strict_types=1);

foreach (['load', 'loadXML', 'loadHTML', 'loadHTMLFile'] as $m) {
    $rf = new ReflectionMethod(DOMDocument::class, $m);
    echo $m,
        ' ret=', $rf->hasReturnType() ? (string) $rf->getReturnType() : 'none',
        ' n=', $rf->getNumberOfParameters(),
        ' req=', $rf->getNumberOfRequiredParameters(),
        "\n";
    foreach ($rf->getParameters() as $p) {
        echo '  ', $p->getName(),
            ' type=', $p->hasType() ? (string) $p->getType() : 'none',
            ' opt=', $p->isOptional() ? '1' : '0';
        if ($p->isDefaultValueAvailable()) {
            echo ' def=', var_export($p->getDefaultValue(), true);
        }
        echo "\n";
    }
}

$doc = new DOMDocument();
echo 'loadXML_ok=', $doc->loadXML('<a/>') ? '1' : '0', "\n";
?>
--EXPECT--
load ret=bool n=2 req=1
  filename type=string opt=0
  options type=int opt=1 def=0
loadXML ret=bool n=2 req=1
  source type=string opt=0
  options type=int opt=1 def=0
loadHTML ret=bool n=2 req=1
  source type=string opt=0
  options type=int opt=1 def=0
loadHTMLFile ret=bool n=2 req=1
  filename type=string opt=0
  options type=int opt=1 def=0
loadXML_ok=1
