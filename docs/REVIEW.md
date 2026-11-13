# Overnight backend build: review

Branch **`backend-overnight`** (from `feature/garden` at `9863492`), 12 Nov 2026. 14 commits, 39 files, nothing pushed.
Detail by step: [PROGRESS.md](PROGRESS.md). Every choice with its reason: [DECISIONS.md](DECISIONS.md).

**In one paragraph:** every page now reads and saves through MySQL. Garden, plants, Story, photos, reminders,
Dashboard, Landing page, Community, listings, interests, swaps, ratings and likes all use the database. The AI care
guide works with your local Ollama, and the ML pages are ready for a Flask service. No page uses session-only
"preview" saving any more. Your data is exactly as it was: no tables dropped, no schema changes, and `seed.sql` was
never run.

---

## 1. What I built, step by step

| Step | Commit | What it does now |
|---|---|---|
| 3.2 Collections (CORE-13) | `dc76d7f` | New collection (with its plants, in one transaction), Add plants, Delete collection (plants stay). Every "CSRF token goes here" placeholder in the project became a real token |
| 3.3 Uploads (CORE-17) | `60c8ab5` | `includes/upload.php`: type checked from the file's bytes (JPG/PNG), 5 MB, at most 40 megapixels, random names in `public/uploads/<folder>/`. `public/uploads/.htaccess` stops PHP running there. Settings saves, replaces and removes the profile photo |
| 3.4 Add / Edit plant (CORE-16) | `cfef7b5` | Add 1–20 plants in one transaction (rows, reminders, first Story entry); Edit with a Story note when health changes; typed species kept as `custom_species`; `no-photo.svg` for plants without a photo |
| 3.5 Plant profile (CORE-18, CORE-21) | `6738fd8` | Story, gallery and cached care guide read from the database; private plants are "not found" to others |
| 3.6 Story, photo, delete (CORE-19, CORE-20) | `6f8be1f` | Add to story (note, milestone, care that moves the reminder on, optional photo), Add photo, Delete plant with its rows and files |
| 3.7 Reminders (CORE-22, CORE-23) | `a359e04` | Add (replaces the same task), Mark done (moves on or finishes a one-off, adds a care line), Undo (puts back exactly what changed) |
| 3.8 Dashboard and Landing (CORE-24/25/27) | `126eed0` | Live counts, newest members, most-liked plant, confident AI examples; Dashboard and Add plant follow the two Settings switches |
| 4.1 Exchange (COM-05, COM-07 – COM-13) | `1a1b178` | Listings, interests, ratings, towns from the database; I'm interested / Withdraw; Mark as exchanged; ratings (each side once); interests marked seen; contact details only via `listing_contact()` |
| 4.2 Listings (COM-06) | `6e0aeaf` | Create, Edit, Close listing (with a confirmation that names it), its photo and categories |
| 4.3 Likes (COM-15) | `568969c` | Like / unlike another member's public plant |
| 5 AI care guide | `742a733` | `includes/llm-client.php` + `public/api/care-guide.php` + `care-guide.js`: Ollama writes a guide once per species, saved only with all five sections; Regenerate for owners |
| 6 ML (PHP side) | `60e1f1a` | `includes/ml-client.php`: Identifier and Health Check send the photo to Flask, log every result, show it, save a diagnosis to the Story, add a 7-day re-check; offline fallback |
| 7 Security | `44b704d` | Root `.htaccess` (only `public/` is served), security headers, errors hidden outside development; full audit |

New files: `includes/garden-data.php`, `includes/exchange-data.php`, `includes/upload.php`, `includes/llm-client.php`,
`includes/ml-client.php`, `public/api/care-guide.php`, `public/assets/js/care-guide.js`, `public/uploads/.htaccess`,
`public/assets/img/no-photo.svg`, `.htaccess`, and these three notes.

**How it was built:** one data function per query, returning rows **with the same keys as the old mock arrays**, so
the pages hardly changed. `mock-data.php` refills its arrays from these functions; then each form was wired and its
session "preview" code deleted. Every write carries its rules in the SQL itself (`… AND user_id = ?`, open listing,
once per member), so a hand-made request cannot get round them.

## 2. Decisions I made (full reasons in DECISIONS.md)

| # | In short |
|---|---|
| D2 | Tests used throwaway `test.*` members, deleted afterwards; `seed.sql` never run |
| D3 | One Obsidian summary note at the end instead of a note per step |
| D4 | **Collection rename and privacy change not built**: My Garden has no UI for them |
| D5 | **GD is off**, so photos are not resized yet (checked and saved as they are) |
| D6–D7 | Upload folders per kind; `.htaccess` lock on `public/uploads/` |
| D8 | Changing health on Edit adds a Story note (keeps `health_status` = newest entry) |
| D9 | Plants added together share one photo file; a file is deleted only when nothing uses it |
| D10 | No photo → species drawing, else `no-photo.svg` |
| D11 | Story and photo popups submit as normal forms (no `fetch()`), so they also work without JavaScript |
| D12 | Past-dated Story entries at 12:00; no future dates; care entries have no health status |
| D13 | Undo takes back the action whose toast is on screen |
| D14 | Landing page shows only public rows and only AI results of 60% or more |
| D15 | The Settings switches "Show care tasks on Dashboard" and "New plants are public" now actually do something |
| D16 | A new interest turns "seen" when the owner opens that listing's table |
| D17 | Contact details are read in one place only, for the owner or a member with a live interest |
| D18 | Rating rules live in the INSERT itself |
| D19 | The care-guide endpoint reads the species from a plant you may see, never from the request |
| D20 | **`llama3.2` is not installed in Ollama**; I did not download it |
| D21 | Regenerate (owner only) replaces the guide for that species |
| D22–D23 | ML pages are ready before Flask exists; a saved diagnosis takes its status from `disease_guides`; unsure results cannot be saved |
| D24 | **Apache was serving `.git`, `seed.sql` and the docs**: fixed with a root `.htaccess` |
| D25 | Security headers; errors hidden unless `app_env` is `development` |

## 3. What to test first

Log in as `nimali.grows` (demo password in `database/README.md`) at http://localhost/plantfolio/public/.

1. **Two quick settings**, so everything works:
   - **GD:** in `C:/xampp/php/php.ini`, remove the `;` before `extension=gd` (line 931), then restart Apache in XAMPP.
   - **Care guide model:** run `ollama pull llama3.2`, *or* add `'llm_model' => 'llama3:latest',` to `config/config.php`.
2. **The care guide in a browser.** This is the one thing I could not test: the Chrome extension was not connected.
   - Open Penny (plant 62) as Nimali: the card shows "Generating", and after about a minute the guide should appear.
   - With the model missing, it should switch to "can't be written right now".
   - Check the browser console stays clean.
3. **A whole plant's life:** Add plant (with a photo and "How many? 2"), open it, Add to story with a photo, Add photo,
   Mark a reminder done and Undo, Edit, Delete. Check each step on a phone width (375 px).
4. **A whole swap with two browsers:**
   - Register a second member in a private window.
   - List a plant as one member, show interest as the other. Contact details appear only then, and only the shared ones.
   - Mark as exchanged, then rate from both sides.
5. **Your own account `rsith99`:** it is in the database (I left it alone). Log in and check your Settings, garden and profile.
6. **Security fix:** http://localhost/plantfolio/.git/config and http://localhost/plantfolio/database/seed.sql should now answer 404.

## 4. Unfinished or risky

**Not built**
- **The Flask ML service and the trained models** (ML-01, ML-03 – ML-10). This needs Colab training. The PHP side
  waits for `POST /predict/species|disease` on `ml_service_url` and was tested against a stand-in.
- **AI species suggestion when a photo is chosen on Add plant** (ML-13). `plant-form.php?ai` is still the stage F
  preview. "Add to my garden" from the Identifier *is* wired.
- **Collection rename and changing a collection's privacy** (D4).
- **`mock-data.php` still exists.** It now refills its arrays from the database. The clean next step is for each page
  to call the data functions directly, then delete the file (planned for Gate 2). The stage F previews (`?ai`,
  `?analysing`, `?offline` without a photo) still use its sample arrays.
- **Obsidian notes:** I wrote one summary note (D3), not the usual per-step entries with "Check yourself" questions.

**Risky or worth knowing**
- **Orphan scan photos.** When Flask is offline, the uploaded photo is kept in `uploads/scans/` (the offline page shows
  it), but nothing in the database points to it. A small clean-up job could delete scans older than a day that no
  result uses.
- **Prompt text from members.** A typed `custom_species` goes into the LLM prompt. The answer is still checked (five
  text sections) and printed escaped, but a cheeky species name could make a misleading guide. That guide is shared
  by anyone who types the same name, and Regenerate replaces it.
- **No Content-Security-Policy header yet.** The pages use inline `style=` attributes, so a CSP needs testing page by page.
- **Seed demo data is changed by normal use**, as it should be. For example, Nimali opening My Exchanges marks her
  4 new interests "seen" (I set them back after my tests). Re-importing `seed.sql` resets everything, **but it also
  deletes `rsith99`**.
- **Apache and MySQL had stopped** when the session resumed (probably a restart). I started them with XAMPP's own
  commands. Use the XAMPP control panel as usual.
- **Not tested on a real phone or by eye.** My checks were HTTP requests, database checks and PHP warnings on every
  page, not screenshots. The layouts did not change, but new messages (form errors, toasts, the Close listing
  dialog, the Like/Liked button) are worth a look at 375 px.
- **My test harness had two bugs, both found and fixed.** The early page checks could not see PHP's HTML-formatted
  warnings, and the sweep could not tell a stopped server from a clean page. Every test was rerun with the fixed
  checks: no warnings, and the same results.
