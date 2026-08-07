<?php
declare(strict_types=1);

/** Issue #28367 — stream_supports() phantom vs stream_supports_lock() on PROFILE≥8.4. */
echo 'stream_supports=', function_exists('stream_supports') ? '1' : '0', "\n";
echo 'stream_supports_lock=', function_exists('stream_supports_lock') ? '1' : '0', "\n";
echo 'STREAM_SUPPORT_READ=', defined('STREAM_SUPPORT_READ') ? '1' : '0', "\n";
