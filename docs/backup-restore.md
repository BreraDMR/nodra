# Backup, restore and migration checks: local stand

Three scripts in the repo root work with the stand's database through Docker (`api-database-1`, database `app`). They only read the stand's database; restores and checks always go into a separate copy.

## Make a backup

```
./scripts/backup.sh
```

Puts a dump in `api/var/backups/app-YYYY-MM-DD-HHMM.sql` (plain SQL, readable by eye). Old dumps are not deleted automatically, so clean the folder by hand when it grows.

Always take a backup before any migration. Before important changes it is also worth making one by hand: `docker exec api-database-1 pg_dump -U app -d app > api/var/backups/app-name.sql`.

## Restore a dump: into a copy, not into the stand

```
./scripts/restore.sh api/var/backups/app-YYYY-MM-DD-HHMM.sql
```

The script recreates the `app_restore` copy from the dump and prints control counters (products, variants, orders, claims). The stand keeps running on its own database. You can work with the copy by putting its name into `DATABASE_URL`, and drop it at the end:

```
docker exec api-database-1 psql -U app -d postgres -c 'DROP DATABASE app_restore'
```

## Check that migrations are reversible

```
./scripts/migcheck.sh        # drops the copy at the end
./scripts/migcheck.sh --keep # keep the app_migcheck copy for inspection
```

The script copies the stand's database into `app_migcheck`, migrates up to the latest, rolls back to zero and migrates up again. On real data this is more reliable than on an empty test database. The copy is dropped after the check (or kept with `--keep`).

## When to run what

| Situation | What to do |
|---|---|
| Before a schema migration | `./scripts/backup.sh`, then the migration |
| New migration in a branch | `./scripts/migcheck.sh` to make sure up and down are clean |
| "Show me how it was yesterday" | `./scripts/restore.sh <dump>` and bring up a second API on the copy |
| The stand's database is broken | the owner restores it by hand: the dump is loaded into the stand manually, and the script never writes there |
