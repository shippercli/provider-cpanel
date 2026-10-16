# Mail and FTP boolean options

Mail and FTP account flags require unquoted YAML booleans. This includes
`create`, `manage_existing`, `update_password`, `delete_data` (mail),
`delete_home` (FTP), and `send_welcome_email` (mail). Quoted strings, numbers,
null and other types fail validation, planning and direct apply before API calls.
Omitting `create` still defaults to true; the other flags default to false.
Use `manage_existing: true` only when you intend to modify an existing account,
and enable deletion flags only when its stored data should be deleted.
