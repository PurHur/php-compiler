<?php
/**
 * Compile must not warn on unbound $arraySliceSlot (#36387 / #37207 extract).
 * Avoid md5 — AOT digest path SIGSEGVs on tip independently; substr is enough.
 */
echo substr('hello', 0, 2), "ok\n";
