# One-time task cleanup

Dependency installation, archive creation/extraction and rollback can use a
monitored cPanel cron task. A private status file prevents the task wrapper from
running its command again after completion.

Cron removal must report success before that status file or its log is deleted.
If removal fails, the provider reports failure and retains both files. Removing
the status file while a cron entry remains could let the command run again.
Failure to delete a completed task's files also fails the operation instead of
being ignored.

A cleanup failure does not mean the command was undone: it may already have
changed application files. Inspect the task and account before retrying. Remove
only the exact failed task, verify that it is no longer scheduled, then remove
its matching status/log files. Do not delete completion markers as a shortcut
while their cron entries may still be active.

Tests cover failed removal, file-cleanup refusal and successful ordering. A local
shell test executes the generated wrapper twice and verifies that a retained
marker prevents its harmless fixture command from repeating. Live cron reload
behavior, races and end-to-end hosting behavior remain unverified.
