<?php

$ao = new ArrayObject(['p' => 'q']);
$ao->setFlags(ArrayObject::ARRAY_AS_PROPS);
echo $ao->p, "\n";

$ao2 = new ArrayObject(['p' => 'q'], ArrayObject::ARRAY_AS_PROPS);
echo $ao2->p, "\n";

$ao->newKey = 'v';
echo $ao->newKey, "\n";
