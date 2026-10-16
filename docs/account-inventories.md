# Mail and FTP inventory validation

Mailbox, forwarder and FTP inventories must be readable arrays of account rows.
Missing data, invalid row identities and duplicate identities fail reconciliation.
Matching uses account rows, without recursively interpreting nested metadata as
resources. Existing resources are preserved unless explicitly managed or owned.

An unreadable inventory also prevents removal of resources dropped from the
configuration. These checks apply within each account-resource category; other
categories or deployment files may already have changed when a later read fails.
This is not a transaction or a complete deployment preflight. Live account
verification remains required.
