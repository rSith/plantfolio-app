# config/

- `config.example.php` — committed template.
- `config.php` — **your local copy, never committed** (listed in `.gitignore`).

```bash
cp config/config.example.php config/config.php
```

| Setting | Needed from | Notes |
|---|---|---|
| `app_env`, `base_url` | Week 1 | `development` shows errors; `production` hides and logs them |
| `timezone` | Week 1 | `Asia/Colombo`, so "due today" follows Sri Lankan time and not the XAMPP default |
| `db_host`, `db_name`, `db_user`, `db_pass` | Week 8 (stage B) | XAMPP default is `root` with no password — never on a shared server |
| `upload_dir`, `max_upload_mb`, `max_image_px` | Week 9 | JPG/PNG only, 5 MB, resized to 1600 px |
| `ml_service_url`, `ml_timeout_seconds` | Week 11 (stage M) | Pages fall back to manual entry if Flask is offline |
| `llm_url`, `llm_model`, `llm_timeout` | AI care guide | Ollama on this computer; online hosting cannot reach it, and the card says so |

**On a hosted copy** set `app_env` to `production` and `base_url` to the site's address + `/public`: see [`docs/SETUP.md`](../docs/SETUP.md).

When a new setting is added, add it to `config.example.php` in the same commit and update your own `config.php`.
