<?php

function f(true|false $x): string
{
    return 't';
}

echo f(true), "\n";
