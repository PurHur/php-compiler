<?php

declare(strict_types=1);

$o = new stdClass();
$wr = WeakReference::create($o);
$o = null;
var_export($wr->get());
