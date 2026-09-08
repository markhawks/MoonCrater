# Architecture

MoonCrater is a server-rendered PHP application backed by PostgreSQL. Apache must expose only
`public/`; application code, migrations, tests, sample imports and documentation stay outside the
web root.

```text
app/          bootstrap, configuration, security, domain and view helpers
bin/          CLI migration, administration and import commands
docs/         architecture and original project artwork
migrations/   ordered, idempotent PostgreSQL migrations
public/       PHP endpoints and static assets (Apache DocumentRoot)
setup/        preflight, installer and Apache examples
tests/        static security and domain tests
var/          runtime uploads/backups; ignored by Git
```

HTTP endpoints retain their historical filenames to avoid changing links. Mutating endpoints
require POST, an administrator session and a CSRF token. The browser never needs access to files
outside `public/`.

Configuration is injected through environment variables. Database schema changes are performed
only by `bin/migrate.php`, never during a web request. `app/version.php` is the single source for
the application version.
