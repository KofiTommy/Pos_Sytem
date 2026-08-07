# POS backup and restore

Run a database backup before updates and at least once each day that the POS is used:

```powershell
powershell -ExecutionPolicy Bypass -File .\tools\backup-database.ps1
```

The backup is saved as a timestamped `.sql` file in `backups/`, which is excluded from Git. Copy that file to secure off-device storage (for example, encrypted cloud storage or an external drive). A backup held only on the POS computer will not protect against disk failure or theft.

The script uses `DB_HOST`, `DB_USER`, `DB_PASS`, and `DB_NAME` environment variables in production. Local XAMPP defaults are `localhost`, `root`, no password, and `possystem_db`.

## Restore

Restoring replaces database contents. Stop using the POS first and make a fresh backup before restoring.

```powershell
& 'C:\xampp\mysql\bin\mysql.exe' --host=localhost --user=root possystem_db < .\backups\possystem-backup-YYYYMMDD-HHMMSS.sql
```

Use the correct host, user, and database values for production. After restoring, sign in and confirm recent sales, stock, users, and uploaded images are present.
