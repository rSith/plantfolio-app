# includes/

Shared PHP logic loaded with `require_once`. Not web-accessible (`.htaccess` denies direct requests).

| File | Purpose | Task · Module | Stage |
|---|---|---|---|
| `helpers.php` | `e()` output escaping, date and "due in N days" formatting, status-label helpers, `photo_url()`; also sends the security headers and hides errors outside development | MOD-01 | F, then B |
| `mock-data.php` | Started as arrays shaped like query results. Since the backend build it refills almost every array from the database (garden-data.php, exchange-data.php) for whoever is logged in, so the pages did not change; what it still holds by hand is the model's supported crops and the sign-up panel's quote (the stage F previews were removed in the release cleanup). Next: pages call the data functions directly and this file goes | MOD-01 | F → B |
| `care-guide.php` | AI care guide: the five sections, `care_guide_species()`, `normalise_species()` (the cache key), `care_guide_get()`, `care_guide_generate()` (asks the LLM once per species, saves only with all five sections), `care_guide_forget()` | AI care guide | F, then B |
| `llm-client.php` | `llm_json()`: one prompt to the local LLM (Ollama, `/api/generate`, JSON), with offline/invalid answers told apart. Settings `llm_url`, `llm_model`, `llm_timeout` | AI care guide | B |
| `garden-data.php` | Every query of the member's garden: plants, collections, reminders, Story, photos, likes, Landing counts; and the writes (collections, plants, Story, reminders, delete, likes), each with its ownership check in the SQL | CORE-13 – CORE-27, COM-15 | B |
| `exchange-data.php` | Every query of the exchange: listings, interests, ratings, towns, other members' public collections, trader numbers, `listing_contact()`; and the writes (listing, interest, exchange, rating, seen) | COM-05 – COM-13 | B |
| `reminder-form.php` | Add a reminder: choices, server-side checks and saving (`reminder_form_save()`), Undo and the toast. Shared by the Dashboard and the Plant Profile | CORE-22, CORE-23 | F, then B |
| `header.php` / `footer.php` | Public and logged-in top navigation, mobile bottom bar, footer; active page highlight. Session check added in stage B | CORE-06 · MOD-01 | F → B |
| `db.php` | One shared PDO connection from `config/config.php` (exceptions on, emulated prepares off) | CORE-03 · MOD-01 | B (week 8) |
| `auth.php` | Sessions (`start_session()`), CSRF tokens, `current_user()`, `attempt_login()` with the lockout, `log_in()` / `log_out()`, `require_login()`. Ownership checks come with each page | CORE-09 – CORE-12 · MOD-03 | B |
| `upload.php` | Secure image upload: `finfo` type check (JPG/PNG), 5 MB, at most 40 megapixels, random names in `public/uploads/<folder>/`, resize to 1600 px when GD is on; `delete_upload()` | CORE-17 · MOD-05 | B |
| `ml-client.php` | cURL client for the Flask service (multipart image, 10 s timeout, answer checked), `ml_predictions` logging and reading, `disease_guide()` and `disease_guide_key()` (a Pl@ntNet result is found by its EPPO code) | ML-02, ML-11 · MOD-11 | B (Flask: `ml-service/app.py`, answers from Pl@ntNet) |
| `disease-guide.php` | AI advice for a Pl@ntNet Health Check result: `disease_guide_generate()` asks the LLM once per problem and saves it in `disease_guides` (crop empty = written by AI), `disease_guide_clean()` checks the answer | ML-14 (D27) | B |

Every page follows the same pattern:
```php
<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/mock-data.php';   // stage F — replaced by db.php + auth.php in stage B
$page_title = 'My Garden';
include __DIR__ . '/../includes/header.php';
?>
<!-- page HTML -->
<?php include __DIR__ . '/../includes/footer.php'; ?>
```

## Logging in to preview the site

Since CORE-09 the login is real (`auth.php`): a new browser is a **visitor**. Log in as Nimali with `nimali.grows` and
the demo password from [`database/README.md`](../database/README.md) to see her full demo garden, or **Create account**
to see the site as a brand-new member with no plants. **Log out** (account menu) makes the browser a visitor again.
The old `?member` and `?guest` shortcuts are gone: they let anyone see a member's pages without a password.

