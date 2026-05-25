/*
 * Output-buffer stack for JIT/AOT ob_*() (issue #118, #1056).
 *
 * LLVM references these globals; this unit provides storage.
 */

#define PHPC_OB_MAX_DEPTH 8
#define PHPC_OB_BUF_SIZE 65536

int __phpc_ob_level = 0;
char __phpc_ob_storage[PHPC_OB_MAX_DEPTH][PHPC_OB_BUF_SIZE];
unsigned long __phpc_ob_len[PHPC_OB_MAX_DEPTH];
