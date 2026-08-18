<?php

error_reporting(E_ALL);
function inner(): void
{
    echo $missing, "\n"; // Zend: this line; VM currently cites inner() below
}
inner();
