<?php

/**
 * #24636 — ExternalMethodBind must hand PHPLLVM\Type objects to Call\Native.
 * Cold-build hello world under HELPER_RUNTIME_O=1 used to TypeError in
 * prependImplicitThisForStaticInstanceCall when binding ErrorSilenceJitHelper.
 */
echo "hi\n";
