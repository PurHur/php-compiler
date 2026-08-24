<?php

declare(strict_types=1);

/**
 * AOT serialize(array) — packed + assoc (#34483).
 * NestedJIT exportKeyValuePairs dim-fetch SIGABRTed; SerializeHashtableLlvm peer JsonEncodeArrayLlvm.
 */
echo serialize([1, 2, 3]), "\n";
echo serialize(['ok' => true, 'n' => 1, 'msg' => 'hi']), "\n";
echo serialize([]), "\n";
echo serialize([[1, 2], ['a' => 3]]), "\n";
