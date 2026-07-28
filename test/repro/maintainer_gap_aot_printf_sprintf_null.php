<?php
// #24258 — AOT printf/sprintf leave %s/%d unsubstituted when value arg is null
printf('%s', null);
echo '|';
printf('%d', null);
echo "\n";
echo sprintf('<%s>', null), "\n";
// control: non-null still works
printf('%s', 'x');
printf('%d', 3);
echo "\n";
