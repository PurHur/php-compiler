<?php
error_reporting(E_ALL);
$h = new SplMaxHeap();
$h->insert(1);
$h->insert(2);
echo 'valid=', $h->valid() ? '1' : '0', ' key=', $h->key(), "\n";
$h->next();
echo 'after_next count=', $h->count(), ' cur=', var_export($h->current(), true), "\n";
