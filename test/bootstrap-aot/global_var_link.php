<?php

declare(strict_types=1);

function init(): void
{
    global $counter;
    $counter = 0;
}

function bump(): void
{
    global $counter;
    $counter = $counter + 1;
}

function read_counter(): void
{
    global $counter;
    echo (string) $counter;
}

init();
bump();
bump();
read_counter();
echo "\n";
