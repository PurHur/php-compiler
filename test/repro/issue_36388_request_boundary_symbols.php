<?php

/**
 * Thin AOT must export phpc_request_begin / phpc_request_end (#36388).
 *
 * The symbols are called from standalone main (php_request_startup/shutdown
 * shape). This script only needs to compile and run; nm checks are in the
 * unit test / verify transcript.
 */
echo "ok\n";
