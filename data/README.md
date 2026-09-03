# ./data

Everything this stack persists, on the host:

- `postgres/` — the PostgreSQL data directory
- `storage/originals/` — resumes exactly as they arrived
- `storage/outputs/` — rendered PDFs
- `secrets/initial-credentials.txt` — the role passwords generated on first boot

Back it up by stopping the stack and copying this directory. It is gitignored in
full, and it holds candidate personal data — keep it off any public path.
