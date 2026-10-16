# Release inventories and staged rollback

Release listing accepts documented `dirs`/`files` mappings and legacy flat rows.
Archive candidates must have an unambiguous basename and ordinary-file type.
Duplicate names and unreadable inventories fail before they authorize changes.
Status exposes `release_inventory.available` and an error for unavailable data;
an unavailable listing is not a verified empty inventory.

## Restore flow

Rollback uploads the packaged Python helper under a random account-home name,
without overwrite permission. A scheduled command checks its SHA-256 digest and
executes the checked bytes. `cpanel.rollback_python_path` defaults to
`/usr/bin/python3`. A working POSIX Python 3 runtime with `fcntl` and `O_NOFOLLOW`
is required. Missing Python or helper integrity failure stops before restoration.
The provider requires successful helper removal after the task. A task failure
reports the random staging path for recovery and review.

The helper checks canonical account-scoped paths and refuses symlink ancestors,
account-root/public_html restoration, and non-ordinary archives. A persistent
no-follow lock inode prevents concurrent helper restores. It verifies the live
manifest, creates a mode-0700 staging directory and mode-0600 archive snapshot,
then validates and extracts that snapshot into an empty tree. It verifies the
archived manifest matches project, profile, domain and deploy path. Old archives
without matching identity are rejected rather than restored by inference.

Validation consumes gzip through its trailer and reads regular-file payloads.
It rejects duplicate canonical member paths, special file types/permission bits,
escaping or cyclic symlinks, non-directory ancestors, and hardlinks that do not
target an archived regular file. Internal links remain supported. Regular files
and directories are staged before links; file/directory modes and mtimes are
preserved without restoring ownership or special permission bits.

A real live `.well-known` directory is copied into the staged tree, replacing
its archived copy. The complete staged tree is then checked again, including
preserved links. A live `.well-known` symlink or copied escaping link is refused.
Only after these checks does promotion rename the active tree to STAGE/previous
and the staged tree to the active path. A failed second rename attempts recovery.
The provider no longer clears the deployment and extracts an unchecked tar into it.

## Recovery and remaining limits

Successful restore retains the previous deployment and archive snapshot in the
account-home `.shipper-restore-<token>.stage` directory. Failure diagnostics
include that path; successful stages can be identified by their journal. Automatic
staging cleanup is not enabled; do not delete it after an uncertain result. The two renames are not
an atomic swap: a brief availability gap exists, and a process crash or failed
recovery rename can require manual restoration from STAGE/previous. Cross-device
promotion is unsupported. The directory is private, but another process running
as the same account can still modify it.

Apply and destroy do not yet share the helper's lock. Concurrent mutation safety
therefore remains incomplete. Account-held manifest consistency is not
cryptographic authentication. Remote Python availability, cPanel command execution,
server permissions and live rollback remain unverified. Review this PR together
with the pending task-unscheduling fix, which preserves completion markers when
Cron removal fails.

The original local reproduction showed that tar readability checks accepted an
external symlink and extraction preserved access to a synthetic outside file.
The generated staged-restore command now rejects that case locally while keeping
the active sentinel unchanged; this is not evidence of live account operation.

## Retention

Rollback safety-backup retention protects both the selected archive and newly
created backup. Other archives outside the newest configured window (minimum
one) are pruned. Protected files can make retention exceed that window. The
selected pin applies only to the current rollback; fresh backups are protected
during their own retention pass. Concurrent external deletion remains possible.

## Offline verification

Run `python3 tests/archive/test_validate_release.py` for member validation,
staging, identity, locking, promotion, and injected failure tests. The PHP suite
also executes the actual generated command against local valid, corrupt,
missing, foreign, external-link and tampered-helper fixtures, plus missing Python.

### Journal-based recovery and explicit cleanup

Promotion writes a private `restore-state.json` journal with account/staging/active
paths and device/inode identities for the stage, previous tree and promoted tree.
Journal replacement is atomic and its file and staging directory are fsynced.
This records prepared, displaced, promoted, recovered and cleaning states.

Run the packaged helper with `--recover STAGE` after an interrupted restore. It
validates the private journal and ancestry, acquires the same restore lock, and
checks actual directory identities. If the active path is absent and the expected
previous tree remains, it restores that tree. If the expected old or new tree is
already active, recovery reports recovered or promoted without overwriting it.
A different active tree or changed previous tree requires review and is refused.
Repeat recovery is safe for these recognized states. Cleanup-in-progress must
be resumed with cleanup rather than recovery.

After confirming a successful restoration, `--cleanup STAGE` removes only the
recorded previous tree, archive snapshot and journal. It requires the promoted
active directory identity, expected previous-directory identity, and no unexpected
staging entries. It never deletes the original release archive or active tree.
Interrupted cleanup records its phase and can be retried; a stage left empty by
interruption after journal deletion may require removing the empty directory.
Recovery/cleanup of pre-journal stages remains manual. Keep the helper package
available; the provider removes its temporary uploaded script after the task.

Tests terminate a real child process immediately after the first rename and
immediately before the second, then recover the original tree twice. They also
exercise confirmed cleanup, interrupted cleanup retry, changed-active refusal and
previous-tree symlink refusal. These are process-interruption checks, not proof
of power-loss durability or live cPanel behavior. Shared apply/destroy locking
and automatic cleanup policy remain unfinished.

### Distribution check

`python3 tests/package/verify-dist.py` builds an actual Composer ZIP, verifies
that runtime source and the restore helper are included, and verifies that
`vendor/`, tests and CI files are excluded. It extracts the archive, loads that
provider class in a fresh PHP process from an unrelated working directory, and
checks that its packaged helper is uploaded intact and scheduled with its digest.
The helper also starts from the extracted package. Dependencies and fake API
fixtures come from the installed source checkout; this test does not contact a
live account or establish a fresh production Composer installation.
