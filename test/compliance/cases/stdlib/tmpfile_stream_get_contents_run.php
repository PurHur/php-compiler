<?php

declare(strict_types=1);

$h = tmpfile();
fwrite($h, 'data');
rewind($h);
var_dump(stream_get_contents($h));
fclose($h);
