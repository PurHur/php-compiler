<?php

function test(): int
{
    try {
        return 1;
    } finally {
        echo 'f';
    }
}

echo test();
