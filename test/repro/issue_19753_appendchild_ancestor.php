<?php
$d = new DOMDocument();
$r = $d->appendChild($d->createElement("r"));
$a = $r->appendChild($d->createElement("a"));
try {
  $a->appendChild($r);
  echo "unexpected_ok\n";
} catch (Throwable $e) {
  echo get_class($e), ": ", $e->getMessage(), " code=", $e->getCode(), "\n";
}
