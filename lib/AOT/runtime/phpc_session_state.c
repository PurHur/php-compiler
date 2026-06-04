/*
 * Session scalar state and id/name buffers for JIT/AOT session_*() (issues #1183–#1184, #5694).
 *
 * LLVM references these globals in standalone AOT objects; this unit provides storage
 * shared with phpc_session_lifecycle.c / phpc_session_storage.c.
 * Limits mirror PHPCompiler\ext\standard\VmSession.
 */

#include <stdint.h>

#define PHPC_SESSION_ID_MAX 128
#define PHPC_SESSION_NAME_MAX 128

char __phpc_session_id_storage[PHPC_SESSION_ID_MAX + 1];
char __phpc_session_name_storage[PHPC_SESSION_NAME_MAX + 1] = "PHPSESSID";

int64_t __phpc_session_id_len = 0;
int64_t __phpc_session_name_len = 9;
char __phpc_session_active = 0;
