<?php
function build(): string
{
    $buf = '';
    for ($i = 0; $i < 100000; ++$i) {
        $buf .= 'x';
    }

    return $buf;
}
echo strlen(build()), "\n";
