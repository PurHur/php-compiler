<?php
// Compile-only (#3383): stream_wrapper_register() JIT/AOT literal lowering registers at compile time.
stream_wrapper_register('aotvar', 'stdClass');
stream_wrapper_unregister('aotvar');
stream_wrapper_restore('aotvar');
