# Sourced from script/apply-patches.sh::patch_already_applied (#36403).
  case "$(basename "$patch")" in
    php-vendor-implicit-nullable-84.patch)
      grep -q '?Block \$prior = null' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/AbstractVisitor.php" 2>/dev/null
      ;;
    php-cfg-class-optional-param-order.patch)
      grep -q 'array \$implements, Block \$stmts, ?Operand \$extends = null' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Stmt/Class_.php" 2>/dev/null
      ;;
    php-llvm-structgep-assert.patch)
      # Match icmp: detect by assert message, not env-var substring (REMOVED_TEST false positive).
      grep -q 'structGep: receiver is not a pointer' "$ROOT/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/Builder.php" 2>/dev/null
      ;;
    php-llvm-icmp-assert.patch)
      grep -q 'iCmp: operands are not of the same type' "$ROOT/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/Builder.php" 2>/dev/null
      ;;
    php-llvm-create-target-machine.patch)
      grep -q 'Int constants match LLVMCodeGenOptLevel' "$ROOT/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/Target.php" 2>/dev/null
      ;;
    php-llvm-builder-dispose-idempotent.patch)
      grep -q 'private bool \$disposed = false' "$ROOT/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/Builder.php" 2>/dev/null \
        || grep -q 'private bool $disposed = false' "$ROOT/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/Builder.php" 2>/dev/null
      ;;
    php-llvm-abstract-ffi-global.patch)
      grep -q 'return \\FFI::string(\$string->getData()\[0\]);' "$ROOT/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/LLVM.php" 2>/dev/null
      ;;
    php-llvm-chooser.patch)
      grep -q 'PHP_COMPILER_LLVM_PATH' "$ROOT/vendor/ircmaxell/php-llvm/lib/Chooser.php" 2>/dev/null
      ;;
    php-llvm-module-createfunctionpassmanager.patch)
      grep -q 'LLVMCreateFunctionPassManagerForModule' "$ROOT/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/Module.php" 2>/dev/null
      ;;
    php-llvm-mcjit-libc-mem.patch)
      grep -q 'Emit libc memset' "$ROOT/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/Intrinsic.php" 2>/dev/null
      ;;
    php-llvm-mcjit-libc-mem-llvm7.patch)
      grep -q 'mem\* helpers use libc via' "$ROOT/vendor/ircmaxell/php-llvm/lib/LLVM7/Intrinsic.php" 2>/dev/null
      ;;
    php-types-binaryop-coalesce.patch)
      grep -q "case 'Expr_BinaryOp_Coalesce':" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php" 2>/dev/null
      ;;
    php-types-cast-object.patch)
      grep -q 'exprType instanceof Type' "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php" 2>/dev/null
      ;;
    php-types-cast-object-resource-stdclass.patch)
      grep -q 'VM Resource wrappers are TYPE_OBJECT but Zend IS_RESOURCE' "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php" 2>/dev/null
      ;;
    php-types-cast-unset.patch)
      grep -q 'resolveOp_Expr_Cast_Unset' "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php" 2>/dev/null
      ;;
    php-types-nullsafe.patch)
      grep -q "case 'Expr_NullsafePropertyFetch':" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php" 2>/dev/null
      ;;
    php-types-static-var.patch)
      # Fixed shape keeps the full default type (#32806); reject the old ->subTypes peel.
      grep -q "case 'Terminal_StaticVar':" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php" 2>/dev/null \
        && grep -q 'return \[\$resolved\[\$op->defaultVar\]\];' "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php" 2>/dev/null \
        && ! grep -q 'defaultVar]->subTypes ??' "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php" 2>/dev/null
      ;;
    php-types-nullable-return.patch)
      grep -q 'CfgType\\Nullable' "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null
      ;;
    php-types-cfg-reference.patch)
      grep -q 'instanceof CfgType\\Reference' "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null
      ;;
    php-types-nullable-optype-return.patch)
      grep -A2 'instanceof Op\\Type\\Nullable' "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php" 2>/dev/null \
        | grep -q 'return (new Type(Type::TYPE_UNION'
      ;;
    php-types-fromvalue-null.patch)
      grep -q 'is_null($value)' "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null
      ;;
    php-types-doc-comment-string.patch)
      grep -q 'instanceof \\PhpParser\\Comment' "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null
      ;;
    php-types-docblock-first-token.patch)
      grep -qF "(@var\\s+(.+?)(?:\\s*\\*\\/|\\s*$))m" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null \
        && grep -qF "(@return\\s+(.+?)(?:\\s*\\*\\/|\\s*$))m" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null
      ;;
    php-types-array-shape.patch)
      grep -qF "preg_match('/array\\{/i', \$decl)" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null \
        && ! grep -qF "preg_match('/^array\\{/i', \$decl)" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null
      ;;
    php-types-iterable-generic.patch)
      grep -qE "preg_match\('/\^\(list\|array\|iterable\)" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null
      ;;
    php-types-generics-fallback.patch)
      grep -q "non-empty-string" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null
      ;;
    php-types-class-generics-fallback.patch)
      grep -q "Class generics from PHPStan/Psalm docblocks: App<T>" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null
      ;;
    php-types-callable-return-strip.patch)
      # Closure(T):R + callable(T):R docblock strip (#8559, #36382 Composer ClassLoader).
      grep -q 'Closure(T):R signatures' "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null
      ;;
    php-types-generics-list-array.patch)
      grep -qE "preg_match\('/\^\(list\|array" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null \
        || grep -qF "preg_match('/^(list|array|iterable)" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null
      ;;
    php-types-generic-null-tail.patch)
      grep -q 'list<T|null> union splits' "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null
      ;;
    php-types-docblock-trailing-text.patch)
      grep -q "stripTrailingDocText" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null
      ;;
    php-types-fromdecl-junk-fragments.patch)
      grep -q 'Malformed phpdoc fragments in vendor trees' "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null
      ;;
    php-types-fromdecl-string-literals.patch)
      grep -q 'Psalm/PHPStan string literal types' "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null
      ;;
    php-types-fromdecl-trailing-comma.patch)
      grep -q 'Docblock union splits may leave a lone' "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null \
        && ! php_types_type_fromdecl_trailing_comma_corrupt "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php"
      ;;
    php-types-remove-type-empty-union.patch)
      ! grep -q "throw new \\\\LogicException('Unknown type encountered')" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null
      ;;
    php-types-ns-func-call.patch)
      grep -q 'function resolveOp_Expr_NsFuncCall' "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php" 2>/dev/null
      ;;
    php-types-arrow-function.patch)
      grep -q 'function resolveOp_Expr_ArrowFunction' "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php" 2>/dev/null
      ;;
    php-types-closure-unbound-this.patch)
      grep -q "is_string(\$op->extra->value) && '' !== \$op->extra->value" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php" 2>/dev/null
      ;;
    php-types-yield-from.patch)
      grep -q "case 'Expr_YieldFrom':" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php" 2>/dev/null
      ;;
    php-types-incdec-type.patch)
      grep -q "case 'Expr_PostInc':" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php" 2>/dev/null
      ;;
    php-types-str-bool-fns.patch)
      grep -q "'str_contains' => \['bool'" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php" 2>/dev/null
      ;;
    php-types-addcslashes-characters.patch)
      grep -q "'addcslashes' => \['string', 'str' => 'string', 'characters' => 'string'\]" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php" 2>/dev/null
      ;;
    php-types-proc-open-array-string.patch)
      grep -q "'proc_open' => \['resource', 'command' => 'array|string'" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php" 2>/dev/null
      ;;
    php-types-explode-array-return.patch)
      grep -q "'explode' => \['array'" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php" 2>/dev/null
      ;;
    php-types-splfixedarray-fromarray-return.patch)
      grep -qF "'SplFixedArray::fromArray' => ['SplFixedArray'" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php" 2>/dev/null
      ;;
    php-types-readfile-int-false.patch)
      grep -q "'readfile' => \['int|false'" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php" 2>/dev/null
      ;;
    php-types-file-array-false.patch)
      # php-src ext/standard/file.stub.php — file(): array|false (#36229 orphan wire-up)
      grep -q "'file' => \['array|false'" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php" 2>/dev/null
      ;;
    php-types-get-meta-tags-array-false.patch)
      grep -q "'get_meta_tags' => \['array|false'" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php" 2>/dev/null
      ;;
    php-types-array-combine-array-false.patch)
      grep -qF "'array_combine' => ['array|false'" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php" 2>/dev/null
      ;;
    php-types-stream-context-array-return.patch)
      grep -q "'stream_context_create' => \['array'" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php" 2>/dev/null
      ;;
    php-types-libxml-get-errors-array-return.patch)
      # php-src ext/libxml/libxml.stub.php — array; bogus object made AOT $errs[0] ArrayAccess-abort (#29161)
      grep -q "'libxml_get_errors' => \['array'" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php" 2>/dev/null
      ;;
    php-types-dom-removeattributenode-return.patch)
      # php-src ext/dom/php_dom.stub.php — removeAttributeNode(): DOMAttr (not bool)
      grep -qF "'DOMElement::removeAttributeNode' => ['DOMAttr'" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php" 2>/dev/null
      ;;
    php-types-strpbrk-string-false.patch)
      grep -q "'strpbrk' => \['string|false'" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php" 2>/dev/null
      ;;
    php-types-error-get-last-null.patch)
      grep -q "'error_get_last' => \['array|null'" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php" 2>/dev/null
      ;;
    php-types-crc32-int.patch)
      grep -q "'crc32' => \['int'" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php" 2>/dev/null
      ;;
    php-types-get-declared-functions.patch)
      grep -q "'get_declared_functions' => \['array'" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php" 2>/dev/null
      ;;
    php-types-realpath-cache-get-array.patch)
      # php-src basic_functions.stub.php — realpath_cache_get(): array (#27665)
      grep -qF "'realpath_cache_get' => ['array']" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php" 2>/dev/null
      ;;
    php-types-realpath-cache-size-int.patch)
      # php-src basic_functions.stub.php — realpath_cache_size(): int (#27664)
      grep -qF "'realpath_cache_size' => ['int']" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php" 2>/dev/null
      ;;
    php-types-get-declared-exclude-deprecated.patch)
      grep -q "'get_declared_classes' => \['array', 'exclude_deprecated=" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php" 2>/dev/null
      ;;
    php-types-gettimeofday-float.patch)
      grep -q "'gettimeofday' => \[''" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php" 2>/dev/null
      ;;
    php-types-round-float.patch)
      grep -q "'round' => \['float'" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php" 2>/dev/null
      ;;
    php-types-link-bool.patch)
      grep -q "'link' => \['bool'" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php" 2>/dev/null
      ;;
    php-types-gc-enabled-bool.patch)
      grep -q "'gc_enabled' => \['bool'\]" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php" 2>/dev/null
      ;;
    php-types-curl-version-arity.patch)
      # php-src ext/curl/curl.stub.php — function curl_version(): array|false (#25585)
      grep -qF "'curl_version' => ['array|false']" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php" 2>/dev/null
      ;;
    php-types-sem-get-auto-release-bool.patch)
      grep -qF "'sem_get' => ['SysvSemaphore|false', 'key' => 'int', 'max_acquire=' => 'int', 'permissions=' => 'int', 'auto_release=' => 'bool']" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php" 2>/dev/null
      ;;
    php-types-openssl-encrypt-aead-args.patch)
      grep -qF "'openssl_encrypt' => ['string|false', 'data' => 'string', 'cipher_algo' => 'string', 'passphrase' => 'string', 'options=' => 'int', 'iv=' => 'string', '&tag=' => 'string', 'aad=' => '?string', 'tag_length=' => 'int']" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php" 2>/dev/null
      ;;
    php-types-openssl-cms-verify-arginfo.patch)
      grep -qF "'openssl_cms_verify' => ['bool', 'input_filename' => 'string', 'flags=' => 'int', 'certificates=' => '?string'" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php" 2>/dev/null
      ;;
    php-types-intltz-get-iana-id-arginfo.patch)
      grep -qF "'IntlTimeZone::getIanaID' => ['string|false', 'zoneId' => 'string']" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php" 2>/dev/null \
        && grep -qF "'intltz_get_iana_id' => ['string|false', 'zoneId' => 'string']" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php" 2>/dev/null
      ;;
    php-types-mysqli-fetch-column-arginfo.patch)
      grep -qF "'mysqli_fetch_column' => ['null|int|float|string|false', 'result' => 'mysqli_result', 'column=' => 'int']" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php" 2>/dev/null \
        && grep -qF "'mysqli_result::fetch_column' => ['null|int|float|string|false', 'column=' => 'int']" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php" 2>/dev/null
      ;;
    php-types-soap-dorequest-arginfo.patch)
      grep -qF "'SoapClient::__doRequest' => ['?string', 'request' => 'string', 'location' => 'string', 'action' => 'string', 'version' => 'int', 'oneWay=' => 'bool']" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php" 2>/dev/null
      ;;
    php-types-ldap-get-option-byref.patch)
      grep -qF "'ldap_get_option' => ['bool', 'link' => '', 'option' => 'int', '&retval' => '']" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php" 2>/dev/null
      ;;
    php-types-hash-init-arginfo.patch)
      grep -qF "'hash_init' => ['HashContext', 'algo' => 'string', 'flags=' => 'int', 'key=' => 'string', 'options=' => 'array']" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php" 2>/dev/null
      ;;
    php-types-json-decode-flags-arginfo.patch)
      # php-src ext/json/json.stub.php — int $flags = 0 (#24812)
      grep -qF "'json_decode' => ['', 'json' => 'string', 'assoc=' => 'bool', 'depth=' => 'int', 'flags=' => 'int']" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php" 2>/dev/null
      ;;
    php-types-idn-arginfo.patch)
      grep -qF "'idn_to_ascii' => ['string|false', 'domain' => 'string', 'flags=' => 'int', 'variant=' => 'int', '&idna_info=' => '?array']" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php" 2>/dev/null \
        && grep -qF "'idn_to_utf8' => ['string|false', 'domain' => 'string', 'flags=' => 'int', 'variant=' => 'int', '&idna_info=' => '?array']" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/InternalArgInfo.php" 2>/dev/null
      ;;
    php-llvm-builder-xor.patch)
      grep -q 'function xor(' "$ROOT/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/Builder.php" 2>/dev/null
      ;;
    php-llvm-no-closures-array-map.patch)
      grep -q 'foreach (\$parameters as \$type)' "$ROOT/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/Context.php" 2>/dev/null \
        && grep -q 'foreach (\$elements as \$type)' "$ROOT/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/Type/Struct.php" 2>/dev/null \
        && ! grep -q 'array_map(' "$ROOT/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/Context.php" 2>/dev/null
      ;;
    php-llvm-pass-registry-interface.patch)
      grep -q "class PassRegistry implements CorePassRegistry" "$ROOT/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/PassRegistry.php" 2>/dev/null
      ;;
    php-llvm-pass-manager-builder-semicolon.patch)
      grep -q 'PassManagerBuilder as CorePassManagerBuilder;' "$ROOT/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/PassManagerBuilder.php" 2>/dev/null
      ;;
    php-llvm-pass-manager-builder-typed-prop.patch)
      grep -q 'LLVMPassManagerBuilderRef $passManagerBuilder' "$ROOT/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/PassManagerBuilder.php" 2>/dev/null
      ;;
    php-llvm-pass-manager-builder-populate.patch)
      grep -q 'PopulateFunctionPassManager($this->passManagerBuilder, $passManager->passManager' "$ROOT/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/PassManagerBuilder.php" 2>/dev/null
      ;;
    php-llvm-context-empty-arrays.patch)
      grep -q '\$paramWrapper = null' "$ROOT/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/Context.php" 2>/dev/null \
        && grep -qE 'count\(\$elements\) > 0|\$elementWrapper = null' "$ROOT/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/Context.php" 2>/dev/null
      ;;
    php-llvm-makearray-empty.patch)
      grep -q 'count(\$elements) === 0' "$ROOT/vendor/ircmaxell/php-llvm/ffi/llvm9.php" 2>/dev/null
      ;;
    php-llvm-memory-buffer-bitcode.patch)
      grep -q 'use llvm\\string_ptr;' "$ROOT/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/MemoryBuffer.php" 2>/dev/null \
        && grep -q '\$this->llvm->lib->getFFI()' "$ROOT/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/MemoryBuffer.php" 2>/dev/null
      ;;
    php-llvm-vector-get-address-space.patch)
      grep -q 'function getAddressSpace' "$ROOT/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/Type/Vector.php" 2>/dev/null
      ;;
    php-llvm-token-type-kind-typo.patch)
      grep -q 'LLVMTokenTypeKind' "$ROOT/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/Type.php" 2>/dev/null \
        && ! grep -q 'LLVMTokenTypeKin' "$ROOT/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/Type.php" 2>/dev/null
      ;;
    php-llvm-void-star-pointer-i8.patch)
      grep -q 'Pointers-to-void are illegal in LLVM bitcode' "$ROOT/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/Type.php" 2>/dev/null
      ;;
    php-llvm-function-getbasicblocks-nparams-typo.patch)
      grep -q "LLVMBasicBlockRef\[' . \$nBlocks . '\]" "$ROOT/vendor/ircmaxell/php-llvm/lib/LLVMAbstract/Value/Function_.php" 2>/dev/null
      ;;
    php-cfg-mixed-reserved.patch)
      [[ -f "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Type/Mixed_.php" ]] \
        && [[ ! -f "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Type/Mixed.php" ]]
      ;;
    php-cfg-nullsafe.patch)
      [[ -f "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/NullsafePropertyFetch.php" ]]
      ;;
    php-cfg-nullsafe-parser.patch)
      grep -q 'function parseExpr_NullsafePropertyFetch' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-cfg-error-suppress-read.patch)
      grep -q 'ZEND_COMPILE_SILENCE' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-cfg-bare-variable-read-stmt.patch)
      grep -q 'ZEND_CHECK_UNDEFINED_VAR (#13587)' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-cfg-error-suppress-cv-temp.patch)
      grep -q '#31881' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null \
        && grep -q '\$assign->result' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-cfg-error-suppress-simplifier.patch)
      grep -q 'instanceof ErrorSuppressBlock' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Visitor/Simplifier.php" 2>/dev/null
      ;;
    php-cfg-strict-types.patch)
      grep -q 'public \$strictTypes' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Func.php" 2>/dev/null
      ;;
    php-cfg-trycatch.patch)
      [[ -f "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Stmt/TryCatch.php" ]] \
        && grep -q 'new Op\\Stmt\\TryCatch' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null \
        && grep -q '\$elseBlock ?? \$endBlock' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null \
        && grep -q 'public \$else;' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Stmt/TryCatch.php" 2>/dev/null
      ;;
    php-cfg-goto-scope.patch)
      grep -q 'gotoLabelScopes' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/FuncContext.php" 2>/dev/null \
        && grep -q 'function validateGotoScope' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-cfg-goto-scope-jumptable.patch)
      # Jumptable switch must push gotoLoopSwitchStack; validateGotoScope throws CompileError (#28796).
      grep -q 'Jumptable path must track switch scope' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null \
        && grep -A6 'function validateGotoScope' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null \
          | grep -q 'throw new \\CompileError'
      ;;
    php-cfg-phi-resolver-null.patch)
      grep -q 'null === \$phi->result' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Visitor/PhiResolver.php" 2>/dev/null
      ;;
    php-cfg-phi-resolver-skip-forwarded.patch)
      grep -q 'forwarded by an earlier resolvePhi pass' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Visitor/PhiResolver.php" 2>/dev/null
      ;;
    php-cfg-magic-constants.patch)
      grep -q 'traitStack' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/AstVisitor/MagicStringResolver.php" 2>/dev/null \
        && grep -q '#26459' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/AstVisitor/MagicStringResolver.php" 2>/dev/null \
        && grep -q 'phpcLexicalScopeKeyword' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/AstVisitor/MagicStringResolver.php" 2>/dev/null
      ;;
    php-cfg-magic-script-const.patch)
      grep -q 'KIND_LINE' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/MagicScriptConst.php" 2>/dev/null
      ;;
    php-cfg-declare-ticks.patch)
      grep -q 'SetTickInterval' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null \
        && grep -q 'LeaveTickInterval' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null \
        && grep -q 'node->stmts' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-cfg-magic-line.patch)
      ! grep -q 'MagicConst\\Line' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/AstVisitor/MagicStringResolver.php" 2>/dev/null
      ;;
    php-cfg-switch-cond-property.patch)
      grep -q 'public \$cond;' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Stmt/Switch_.php" 2>/dev/null
      ;;
    php-cfg-loop-resolver-nested.patch)
      grep -q 'array_slice(\$stack, -\$num, 1)' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/AstVisitor/LoopResolver.php" 2>/dev/null
      ;;
    php-cfg-loop-resolver-continue-switch-warning.patch)
      grep -q 'compiler_language_warning' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/AstVisitor/LoopResolver.php" 2>/dev/null \
        && grep -q 'continue %d' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/AstVisitor/LoopResolver.php" 2>/dev/null
      ;;
    php-cfg-loop-resolver-break-outside-context.patch)
      grep -q "not in the 'loop' or 'switch' context" "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/AstVisitor/LoopResolver.php" 2>/dev/null
      ;;
    php-cfg-loop-resolver-break-continue-positive.patch)
      grep -q "operator accepts only positive integers" "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/AstVisitor/LoopResolver.php" 2>/dev/null
      ;;
    php-cfg-no-arrow-function.patch)
      ! grep -q 'fn (Op\\Type $t) => ' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Printer.php" 2>/dev/null
      ;;
    php-cfg-no-closure-preg-replace-callback.patch)
      grep -q "repairCommentsCallback" "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/AstVisitor/MagicStringResolver.php" 2>/dev/null \
        && grep -q "docCommentTypeCallback" "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/AstVisitor/NameResolver.php" 2>/dev/null
      ;;
    php-cfg-property-type.patch)
      grep -q 'public $type;' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Stmt/Property.php" 2>/dev/null \
        && grep -q 'function __construct(Operand $name, int $visibility' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Stmt/Property.php" 2>/dev/null
      ;;
    php-cfg-typed-class-const.patch)
      grep -q 'public ?Type $declaredType' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Terminal/Const_.php" 2>/dev/null
      ;;
    php-cfg-class-const-flags.patch)
      grep -q 'public int $flags = 0' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Terminal/Const_.php" 2>/dev/null
      ;;
    php-cfg-yield-from.overlay)
      grep -q 'function parseExpr_YieldFrom' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null \
        && [[ -f "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/YieldFrom.php" ]]
      ;;
    php-cfg-shell-exec.overlay)
      grep -q 'function parseExpr_ShellExec' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-cfg-asymmetric-visibility.patch)
      grep -q 'public int \$setVisibility' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Stmt/Property.php" 2>/dev/null \
        && grep -q 'promotionSetVisibility' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/Param.php" 2>/dev/null
      ;;
    php-cfg-lazy-property.patch)
      grep -q 'public bool \$propertyLazy' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Stmt/Property.php" 2>/dev/null \
        && grep -q 'function extractLazyPropertyFromAttributes' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-cfg-assertion-expr-property.patch)
      grep -q 'public $expr;' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/Assertion.php" 2>/dev/null
      ;;
    php-cfg-match.patch)
      grep -q 'function parseExpr_Match' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null \
        && grep -q 'lowerUnhandledMatchError' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null \
        && grep -q 'phpc_match_unhandled_operand_message' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-cfg-match-multi-cond-block.patch)
      # Overlay in patches/overlays/php-cfg/match-parser-methods.php already inserts
      # $this->block = $nextBlock without the comment — accept either marker (#30442).
      grep -q 'not after the JumpIf terminator' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null \
        || grep -q '\$this->block = \$nextBlock;' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-cfg-incdec-expr.patch)
      grep -q 'new Op\\Expr\\PostInc' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null \
        && [[ -f "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/PostInc.php" ]]
      ;;
    php-cfg-halt-compiler.patch)
      grep -q 'new Op\\Stmt\\HaltCompiler' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-cfg-assignop-coalesce.patch)
      grep -q "'Expr_AssignOp_Coalesce'" "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-cfg-list-destruct-byref.patch)
      grep -q 'if (\$item->byRef)' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null \
        && grep -A3 'parseListAssignment' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null \
          | grep -q 'AssignRef'
      ;;
    php-cfg-list-assignment-attr.patch)
      grep -q "attributes\['listAssignment'\] = true" "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null \
        || grep -q '\$attributes\['\''listAssignment'\''\] = true' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-cfg-empty-list-assignment.patch)
      grep -q 'isEmptyListExpr' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null \
        && grep -q "Cannot use empty list" "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-cfg-list-mix-keyed-unkeyed.patch)
      grep -q 'rejectMixedKeyedUnkeyedListAssignment' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null \
        && grep -q "Cannot mix keyed and unkeyed array entries in assignments" "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-cfg-list-skip-slot.patch)
      grep -A3 'null === $item' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null \
        | grep -q '++$logicalIndex'
      ;;
    php-cfg-list-spread.patch)
      grep -q 'listSpreadRhs' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/Assign.php" 2>/dev/null \
        && grep -q '\$item->unpack' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-cfg-first-class-callable.patch)
      grep -q 'isFirstClassCallable' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-cfg-new-first-class-callable.patch)
      grep -q 'KIND_NEW' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/FirstClassCallable.php" 2>/dev/null \
        && grep -q 'FirstClassCallable::KIND_NEW' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-cfg-nullsafe-first-class-callable.patch)
      grep -q 'Cannot combine nullsafe operator with Closure creation' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-cfg-arrow-function.patch)
      grep -q 'function parseExpr_ArrowFunction' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-cfg-anonymous-class.patch)
      grep -q 'parseStmt_Class($expr->class)' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-cfg-new-ctor-parens.patch)
      grep -q 'newHasCtorParens' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-cfg-enum.patch)
      grep -q 'parseStmt_Enum' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null \
        && php_cfg_enum_implements_parser_applied
      ;;
    php-cfg-enum-implements.patch)
      grep -q 'public $implements' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Stmt/Enum_.php" 2>/dev/null \
        && php_cfg_enum_implements_parser_applied
      ;;
    php-cfg-enum-class-method.patch)
      grep -q 'elseif ($stmt instanceof Stmt\\ClassMethod)' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-cfg-enum-class-const.patch)
      grep -q 'public bool $isEnumCase = false' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Terminal/Const_.php" 2>/dev/null \
        && grep -q 'Stmt\\ClassConst' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null \
        && grep -A30 'function parseStmt_Enum' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null | grep -q 'ClassConst'
      ;;
    php-cfg-enum-trait-use.patch)
      grep -q 'function parseStmt_Enum' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null \
        && grep -A35 'function parseStmt_Enum' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null | grep -q 'Stmt\\TraitUse'
      ;;
    php-cfg-enum-abstract.patch)
      php_cfg_enum_flags_parser_applied
      ;;
    php-cfg-named-args.patch)
      grep -q 'callArgName' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Operand.php" 2>/dev/null \
        && grep -q 'callArgName' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-cfg-spread.patch)
      grep -q 'parseCallArgs($expr->args)' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null \
        && ! grep -q 'parseExprList($expr->args, self::MODE_READ)' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-cfg-never-type.patch)
      [[ -f "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Type/Never_.php" ]]
      ;;
    php-cfg-intersection-type.patch)
      [[ -f "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Type/Intersection.php" ]]
      ;;
    php-cfg-instanceof-union.patch)
      grep -q 'parseInstanceofClassUnion' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-cfg-union-type.patch)
      [[ -f "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Type/Union_.php" ]]
      ;;
    php-cfg-ctor-promotion.patch)
      grep -q '\$p->promotionFlags = \$param->flags' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-cfg-ctor-promotion-readonly.patch)
      grep -q '\$p->promotionReadonly' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-cfg-readonly-function.patch)
      grep -q 'FLAG_READONLY' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Func.php" 2>/dev/null \
        && grep -A25 'function parseExpr_Closure' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null \
          | grep -q "compilerReadonlyFunction" \
        && { ! grep -q 'function parseExpr_ArrowFunction' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null \
          || grep -A25 'function parseExpr_ArrowFunction' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null \
            | grep -q "compilerReadonlyFunction"; } \
        && grep -A12 'function parseStmt_Function' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null \
          | grep -q "compilerReadonlyFunction"
      ;;
    php-cfg-property-readonly.patch)
      grep -qE 'propertyFlags = \$node->flags|\$cfgProp->readonly =|\$prop->readonly =|\$property->readonly =|->readonly = 0 !== \\(\\$node->flags & .*MODIFIER_READONLY\\)' \
        "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-parser-final-property.patch)
      grep -q 'PHP_COMPILER_FINAL_PROPERTY' \
        "$ROOT/vendor/nikic/php-parser/lib/PhpParser/ParserAbstract.php" 2>/dev/null
      ;;
    php-cfg-attribute-groups.patch)
      grep -q "attrGroups'\] = \$expr->attrGroups" "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-cfg-trait-use.patch)
      [[ -f "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Stmt/TraitUse.php" ]] \
        && ! grep -A8 'function parseStmt_TraitUse' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null | grep -q '// TODO'
      ;;
    php-cfg-throw-expr.patch)
      grep -q 'return new Op\\Expr\\Throw_' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null \
        || grep -q 'parseExpr_Throw' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null \
        || [[ -f "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Op/Expr/Throw_.php" ]]
      ;;
    php-cfg-is-resource-no-assertion.patch)
      ! grep -q "'is_resource' => 'resource'" "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-cfg-assertion-fn-arity.patch)
      grep -q 'isset($assertionFunctions\[$lname\]) && isset($args\[0\])' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null \
        || grep -q 'isset($assertionFunctions[$lname]) && isset($args[0])' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Parser.php" 2>/dev/null
      ;;
    php-cfg-simplifier-use-chain.patch)
      # July 5 partial apply added replaceVariablesByCfgWalk but kept legacy default;
      # the #23070 flip (2026-07-25) rewrites replaceVariables — guard on the new opt-out.
      grep -q "getenv('PHPCFG_SIMPLIFIER_LEGACY')" "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Visitor/Simplifier.php" 2>/dev/null
      ;;
    php-cfg-operand-usage-dedup.patch)
      grep -q 'usageIds' "$ROOT/vendor/ircmaxell/php-cfg/lib/PHPCfg/Operand.php" 2>/dev/null
      ;;
    php-types-resolver-worklist.patch)
      grep -q 'PHPTYPES_RESOLVER_LEGACY' "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php" 2>/dev/null
      ;;
    php-types-never-type.patch)
      grep -q 'function never(): self' "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null \
        && grep -q 'instanceof CfgType\\Never_' "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null \
        && grep -q "case 'never':" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null \
        && grep -q 'Op\\Type\\Never_' "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php" 2>/dev/null
      ;;
    php-types-intersection-type.patch)
      grep -q 'instanceof CfgType\\Intersection' "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null
      ;;
    php-types-union-type.patch)
      grep -q 'instanceof CfgType\\Union_' "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/Type.php" 2>/dev/null \
        && grep -q 'instanceof Op\\Type\\Union_' "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php" 2>/dev/null
      ;;
    php-types-throw-expr.patch)
      grep -q "case 'Expr_Throw':" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php" 2>/dev/null
      ;;
    php-types-magic-script-const.patch)
      grep -q 'KIND_LINE === \$op->kind' "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php" 2>/dev/null
      ;;
    php-types-first-class-callable.patch)
      grep -q 'Expr_FirstClassCallable' "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/TypeReconstructor.php" 2>/dev/null
      ;;
    php-types-missing-parent-no-echo.patch)
      ! grep -q "Could not find parent" "$ROOT/vendor/ircmaxell/php-types/lib/PHPTypes/State.php" 2>/dev/null
      ;;
    pre-plugin-autoload-prepend.patch)
      grep -q 'prepend: true' "$ROOT/vendor/pre/plugin/source/autoload.php" 2>/dev/null \
        && ! grep -q ', false,' "$ROOT/vendor/pre/plugin/source/autoload.php" 2>/dev/null
      ;;
    pre-plugin-parser-macros.patch)
      grep -q 'private array \$macros' "$ROOT/vendor/pre/plugin/source/Parser.php" 2>/dev/null
      ;;
    *)
      return 1
      ;;
  esac
