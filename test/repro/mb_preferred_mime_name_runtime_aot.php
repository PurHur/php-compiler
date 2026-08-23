<?php

declare(strict_types=1);

$enc = 'UTF-8';
echo mb_preferred_mime_name($enc), "\n";
echo mb_preferred_mime_name('ASCII'), "\n";
echo mb_preferred_mime_name('SJIS'), "\n";
