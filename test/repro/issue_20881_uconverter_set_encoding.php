<?php
$c = new UConverter('utf-8', 'iso-8859-1');
echo 'src=', $c->getSourceEncoding(), ' dst=', $c->getDestinationEncoding(), "\n";
foreach (['setSourceEncoding', 'setDestinationEncoding', 'getAlgorithms'] as $m) {
    echo $m, '=', method_exists($c, $m) ? 'yes' : 'no', "\n";
}
echo 'set_src=', $c->setSourceEncoding('utf-8') ? '1' : '0', ' src=', $c->getSourceEncoding(), "\n";
echo 'set_dst=', $c->setDestinationEncoding('iso-8859-1') ? '1' : '0', ' dst=', $c->getDestinationEncoding(), "\n";
echo 'bad=', $c->setSourceEncoding('not-a-real-encoding-xyz') ? '1' : '0', "\n";
echo 'src_after_bad=', $c->getSourceEncoding(), "\n";
echo 'err=', $c->getErrorCode(), ':', $c->getErrorMessage(), "\n";
