<?php
$c = new UConverter('utf-8', 'iso-8859-1');
echo 'set_src=', (int) method_exists($c, 'setSourceEncoding'), "\n";
echo 'set_dst=', (int) method_exists($c, 'setDestinationEncoding'), "\n";
echo 'ok_src=', (int) $c->setSourceEncoding('utf-8'), ' src=', $c->getSourceEncoding(), "\n";
echo 'ok_dst=', (int) $c->setDestinationEncoding('iso-8859-1'), ' dst=', $c->getDestinationEncoding(), "\n";
echo 'bad=', (int) $c->setSourceEncoding('not-a-real-encoding-xyz'), "\n";
echo 'src_kept=', $c->getSourceEncoding(), "\n";
echo 'err=', $c->getErrorCode(), "\n";
