<?php
try { is_array(); echo "ran\n"; } catch (Throwable $e) { echo get_class($e), ": ", $e->getMessage(), "\n"; }
try { is_callable(); echo "ran\n"; } catch (Throwable $e) { echo get_class($e), ": ", $e->getMessage(), "\n"; }
var_dump(is_array([]));
