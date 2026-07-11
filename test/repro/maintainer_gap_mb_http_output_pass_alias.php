<?php

declare(strict_types=1);

mb_http_output('UTF-8');
$before = mb_http_output();

$set = mb_http_output('pass');
$after = mb_http_output();

echo $set ? 'set ok' : 'set fail', "\n";
echo $after === 'pass' ? 'getter pass' : 'getter '.$after, "\n";

mb_http_output('SJIS');
echo mb_http_output() === 'SJIS' ? 'sjis ok' : 'sjis fail', "\n";
mb_http_output('pass');
echo mb_http_output() === 'pass' ? 'pass after sjis' : 'pass after sjis fail', "\n";

mb_http_output($before);
echo mb_http_output() === $before ? 'restored' : 'restore fail', "\n";
