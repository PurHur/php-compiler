<?php
/** Repro #21459 — ctype_blank() phantom: must not exist (php-src ext/ctype/ctype.c). */
echo function_exists('ctype_blank') ? "exists:yes\n" : "exists:no\n";
