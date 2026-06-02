/*
 * Session scalar state for JIT/AOT session_*() (issues #1183–#1184, #1882).
 *
 * LLVM references these globals in standalone AOT objects; this unit provides storage
 * shared with phpc_session_lifecycle.c / phpc_session_storage.c.
 */

#include <stdint.h>

int64_t __phpc_session_id_len = 0;
int64_t __phpc_session_name_len = 9;
char __phpc_session_active = 0;
