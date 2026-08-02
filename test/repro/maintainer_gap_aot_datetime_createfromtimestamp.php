<?php
// #26936 — AOT DateTime(Immutable)::createFromTimestamp must match VM/JIT.
echo DateTime::createFromTimestamp(1700000000)->format('U'), "\n";
echo DateTimeImmutable::createFromTimestamp(1700000000.5)->format('U.u'), "\n";
