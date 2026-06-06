/**
 * JIT/AOT segfault triage — async-signal-safe SIGSEGV handler only (#2978, #6748, #6777).
 * Progress string buffering lives in LLVM globals filled by ProgressNoteRuntime.php.
 */
#include <stddef.h>
#include <unistd.h>
#include <signal.h>

extern char phpc_last_progress[256];
extern size_t phpc_last_progress_len;

static void phpc_segv_handler(int sig)
{
    (void) sig;
    if (phpc_last_progress_len > 0) {
        (void) write(2, "phpc: fatal signal (segfault) after ", 37);
        (void) write(2, phpc_last_progress, phpc_last_progress_len);
        (void) write(2, "\n", 1);
    } else {
        (void) write(2, "phpc: fatal signal (segfault)\n", 30);
    }
    _exit(139);
}

__attribute__((constructor))
static void phpc_install_segv_handler(void)
{
    (void) signal(SIGSEGV, phpc_segv_handler);
}
