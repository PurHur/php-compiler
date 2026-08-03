<?php
/**
 * #27060 — AOT getprotobyname("tcp") must compile (no NestedJIT HT static store).
 */
echo getprotobyname('tcp'), PHP_EOL;
