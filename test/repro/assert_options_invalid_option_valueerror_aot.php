<?php
// #30524 AOT probe (no set_error_handler; avoid foreach assign lowering gap)
try { var_export(assert_options(0)); echo "\n"; } catch (Throwable $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
try { var_export(assert_options(999)); echo "\n"; } catch (Throwable $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
try { var_export(assert_options(null)); echo "\n"; } catch (Throwable $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
try { var_export(assert_options(999, 1)); echo "\n"; } catch (Throwable $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
echo 'active=', var_export(assert_options(ASSERT_ACTIVE), true), "\n";
