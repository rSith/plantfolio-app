# PlantFolio QA report

**Date:** 12 Nov 2026 · **Branch:** `feature/ml-service` · **Tested on:** XAMPP (Apache 2.4.58, PHP 8.2.12, MariaDB 10.4.32),
Flask ML service on port 5000, Ollama (`llama3:latest`) on port 11434.

**Before anything was changed:** the database was exported to `database/backup_before_qa.sql` (18 tables; git-ignored,
because it holds real members' emails and password hashes) and the working tree was committed (`d7c11dd`).

## 1. Summary

| | |
|---|---|
| Scripted checks (one request or database check each, with an expected answer) | **449** |
| Passed first time | 438 |
| Failed first time | 11 (they exposed 8 of the bugs below; the others were found by the browser pass and by reading the code) |
| Page sweep | 70 addresses × visitor and member = 140 requests, run 3 times with `error_reporting(E_ALL)` |
| Browser checks (headless Edge, 375 / 768 / 1280 px) | 249 page-and-width checks on 83 addresses |
| **Bugs found** | **16** (2 High, 7 Medium, 7 Low) |
| **Fixed and re-tested** | **16** |
| Still open (noted, not changed) | 12 items in section 6: none stops a core feature |

**The 9 core features work end to end:** registration and login, plant profiles with care information, collections,
health tracking (the Story), care reminders, search and discovery, exchange listings, user ratings, and the responsive
layout. Each was tested through the real pages with throwaway accounts (`qa.*`), which were deleted afterwards. The demo
data and the real member accounts already in the local database were not changed: every table has the same number of rows as
at the start, except one Plant Identifier result and one care guide that you made yourself at 17:42 during the test.

**What was already solid (no bug found):** every SQL statement uses prepared statements; every value printed into a page
goes through `e()` (all 1419 `<?= ?>` tags were scanned, and `<script>` typed into every text field came back as plain
text); every POST form has a CSRF token and every POST handler checks it; another member's ID in the address or in a
hand-made form never read or changed anything; private plants answer "not found"; contact details appear only after
"I'm interested"; nobody can rate themselves, rate twice or rate an exchange they were not part of; uploads accept only
real JPG and PNG files up to 5 MB; `.git`, `config/`, `includes/`, `partials/`, `database/` and `ml-service/` cannot be
opened from the browser; no secret is tracked in git or in its history.

## 2. Bugs

Severity: **High** = a member loses data or sees a crash in a normal flow · **Medium** = wrong result, privacy or security
weakness · **Low** = cosmetic, or only with unusual input.

| ID | Severity | Area | Page / file | Problem | Fix | Status |
|---|---|---|---|---|---|---|
| BUG-01 | High | Exchange | `public/listing-form.php` | Listing a garden plant that has **no photo** ended in a database error page ("Column 'photo' cannot be null"). A plant may be saved without a photo; a listing may not | The form now stops with a message under the plant picker: add a photo on the plant's Edit page, or choose "Not in my garden" and upload one | Fixed, re-tested |
| BUG-02 | High | Plants | `public/plant-form.php` | Editing a plant whose species was **typed by hand** (not in our species list) opened the form with an empty Species field, so pressing Save deleted the species. "Add another like this" had the same gap | The form reads the typed species (`custom_species`) when there is no listed species | Fixed, re-tested |
| BUG-03 | Medium | Security (CSRF) | `public/logout.php`, `includes/header.php` | Log out was a plain link. Any other website could log a member out with one hidden image | Log out is now a small POST form with the CSRF token; opening `logout.php` as a link does nothing | Fixed, re-tested |
| BUG-04 | Medium | Privacy | `public/settings.php`, `includes/garden-data.php` | Deleting an account removed the rows but left **all the member's photo files** in `public/uploads/`, still reachable by their address | The files are listed before the rows go (`member_photo_files()`), then each is deleted unless another row still uses it | Fixed, re-tested |
| BUG-05 | Medium | Uploads | `includes/upload.php` | Photos taken with a phone held upright were saved **lying on their side**: resizing dropped the EXIF "orientation" note that tells viewers to turn the picture | The picture is turned (EXIF orientation 3, 6, 8) before it is re-drawn | Fixed, re-tested |
| BUG-06 | Medium | Reminders | `public/plant.php` | Adding a Story entry "Watered" for an **earlier day** pulled the water reminder back into the past (it became overdue although it had been done since) | The reminder moves only when the entry is the newest time the task was done | Fixed, re-tested |
| BUG-07 | Medium | Health Check | `public/health-check.php` | Pressing "Save to story" twice (double click, or Back and Save again) wrote the **same diagnosis twice** | One Story entry per result and plant | Fixed, re-tested |
| BUG-08 | Medium | Responsive | `public/assets/css/components.css` | Text typed as one long word with no spaces (nickname, title, name, note, species) pushed its box wider than the screen: the whole page scrolled sideways on Plant Profile, Listing Detail, Public Profile, My Exchanges and both forms, at every width | `overflow-wrap: anywhere` on `body`, the rule the project already used on single elements. Normal words are never split | Fixed, re-tested (84 page-width checks with worst-case text) |
| BUG-09 | Medium | Ratings | `public/user.php` | A review whose writer later **deleted their account** printed PHP notices, a blank name and a link to nobody | Shows "Former member" without a link | Fixed, re-tested |
| BUG-10 | Low | Exchange | `public/listing.php`, `partials/listing-card.php`, `includes/helpers.php` | A listing saved without "Looking for" (the field is optional) showed an empty "Looking for" and a PHP notice | New helper `listing_wants()`: a free listing says "nothing, free to a good home", a swap "open to offers", the same words as the form's preview | Fixed, re-tested |
| BUG-11 | Low | Security | `public/.htaccess` (new) | `public/api/`, `public/assets/` and their subfolders showed a **list of their files** | `Options -Indexes`: such an address answers 403 | Fixed, re-tested |
| BUG-12 | Low | Login | `public/login.php` | A visitor sent to Log in from *any* members-only page was told "Log in to show your interest… back to the listing" | That sentence only after a listing; otherwise "Log in to continue" | Fixed, re-tested |
| BUG-13 | Low | Exchange | `public/listing-form.php` | The live preview on Create Listing showed a broken image while no plant was chosen (it pointed at `uploads/empty-garden.svg`) | Uses the neutral no-photo picture | Fixed, re-tested |
| BUG-14 | Low | Exchange | `public/listing-form.php` | Opening Edit for a listing with no description printed a PHP notice (`mb_strlen(null)`) | Empty text instead of NULL in the form | Fixed, re-tested |
| BUG-15 | Low | Exchange | `public/listing-form.php` | The suggested title for a plant with no species was " from Spike" (leading space, no name) | The nickname alone | Fixed, re-tested |
| BUG-16 | Low | Repository | `.gitignore` | The requested backup file would have been committed with members' emails and password hashes | `database/backup_*.sql` is ignored | Fixed |

## 3. Files changed

| File | Why |
|---|---|
| `public/listing-form.php` | BUG-01 message instead of a crash · BUG-13 preview picture · BUG-14 NULL description · BUG-15 suggested title |
| `public/plant-form.php` | BUG-02 keep a typed species on Edit and on "Add another" |
| `public/logout.php` | BUG-03 log out only on a POST with the CSRF token |
| `includes/header.php` | BUG-03 "Log out" is a form button (styled by the existing `button.menu-item`) |
| `public/settings.php` | BUG-04 delete the member's photo files with the account; rollback if the delete fails |
| `includes/garden-data.php` | BUG-04 new `member_photo_files()` |
| `includes/upload.php` | BUG-05 turn phone photos upright before resizing |
| `public/plant.php` | BUG-06 a back-dated care entry no longer rewinds its reminder |
| `public/health-check.php` | BUG-07 a diagnosis is saved to the Story once |
| `public/assets/css/components.css` | BUG-08 long words wrap |
| `public/user.php` | BUG-09 reviews by former members |
| `includes/helpers.php` | BUG-10 new `listing_wants()` |
| `public/listing.php`, `partials/listing-card.php` | BUG-10 use `listing_wants()` |
| `public/.htaccess` (new) | BUG-11 no folder listings |
| `public/login.php` | BUG-12 wording |
| `.gitignore` | BUG-16 ignore QA backups |
| `CLAUDE.md` | Not a fix: your own waiting edits were committed first, so the tree was clean (rule 1) |
| `QA_REPORT.md` (new) | This report |

No design token, component or file was renamed or moved. No library was added.

## 4. Database changes

**None.** No table, column, index or constraint was changed, and no SQL was run against the schema.

- `database/schema.sql` and `seed.sql` import cleanly into an empty database, twice in a row, also in strict mode
  (tested in a scratch database `pf_qa_scratch`, which you can drop: `DROP DATABASE pf_qa_scratch;`).
- The live `plantfolio` database has exactly the columns, 26 foreign keys and indexes that `schema.sql` creates.
  Every foreign key, `care_reminders.next_due`, `plants.health_status`, `plants.is_public` and
  `exchange_listings (status, created_at)` is indexed.
- Integrity checks, all 0 rows: orphan plants, Story entries, reminders, listings, interests; listings without a
  category; self-ratings; ratings on open listings; plants with both a listed and a typed species; plant health that
  disagrees with its newest Story entry; photo paths whose file is missing.
- Test data: the `qa.*` accounts were removed through the app's own Delete account. Four demo rows that a page view
  changes (Nimali's new interests 18–21 turn "seen" when her My Exchanges opens) were set back with
  `UPDATE listing_interests SET status='new' WHERE id IN (18,19,20,21) AND status='seen';`
- The only data SQL besides that: `DELETE FROM care_guides WHERE species='rare <b>thing</b>';` (the guide my test generated).

## 5. Not tested

| Item | Why |
|---|---|
| A real phone | Needs a device. Layout was measured at 375 px in headless Edge only |
| Clicking through popups, menus, toasts, the live username check, the live listing preview and the no-reload Community filters | NOT TESTED – the Chrome extension was not connected, so there was no way to click. Checked instead: every page loads without a console error at three widths, and every form was tested by sending it directly |
| Hover and keyboard-focus styles by eye | NOT TESTED – same reason |
| The ML service really switched off | NOT TESTED as such – stopping the Flask process was not permitted. Simulated instead by pointing PHP at a closed port for one test run (reverted): Identify, Health Check and "Identify plant" on Add plant all showed the friendly "couldn't reach" state, nothing was logged, and the rest of the site kept working |
| An LLM answer that takes longer than the time limit | NOT TESTED – would need Ollama slowed down. Tested instead: a model that does not exist (Ollama answers 404) gives the "offline" state and saves nothing; a real generation took 20 s and was cached |
| Our own MobileNetV2 disease model | NOT TESTED – it does not exist. Since decision D27 the Health Check uses the Pl@ntNet disease API (`ml-service/models/` is empty). The README and the report text should say so |
| Accuracy of the AI answers | Out of scope for bug testing. One real photo: species "Ficus lyrata" 71% (Medium), disease "Unknown parasitic pathogen" 44% (see item 4 below) |
| Email reminders | The switch is disabled in the UI; there is no code behind it |
| The Vercel preview | Not deployed during this test (its files were removed in the release cleanup afterwards) |
| "Health-status colours only for health status" by eye | Checked in the CSS instead: see item 9 below |

## 6. Remaining risks and next steps before submission

1. **Preview addresses from the frontend stage still work** and show sample data: `?empty=1` on any member page,
   `identify.php?analysing`, `identify.php?offline`, `health-check.php?analysing|offline`, `plant-form.php?ai`,
   `plant.php?id=…&guide=offline`. `includes/mock-data.php` still holds about 950 lines of sample arrays that the
   database overwrites; only the six sample results at these addresses and the sign-up panel's quote
   (`$mock_quote_member`, "64 plants") are still read. Nothing links to them, so they were left alone (they are your
   documented choice). Before submission: remove them, or allow them only when `app_env` is `development`.
2. **Set `app_env` to something other than `development` for the demo.** With `development`, a PHP error prints file
   paths on the page.
3. **Photos kept after "couldn't reach".** When the ML service does not answer, the uploaded photo stays in
   `uploads/scans/` with no row pointing at it (already noted in docs/REVIEW.md). A small clean-up of unreferenced scans
   older than a day would close it.
4. **Pl@ntNet's "Unknown parasitic pathogen" (code `ZZXXZZ`) is shown as a "Possible match"** and can be saved to the
   Story. It is the API's way of saying "something, but I don't know what". Consider treating that code as "Not sure".
5. **Small tap targets at 375 px** (under 24 px high): "Forgot password?", the Swap badge on Community plant cards, the
   stack label on My Garden, "Change" beside the email in Settings.
6. **Changing the password does not log out other browsers that are already logged in** (it does cancel every
   "Remember me" token). Acceptable for coursework; say so if asked.
7. **No limit on how often someone may sign up, check usernames or start an AI guide.** A typed species goes into the
   LLM prompt, so a nonsense name gets a confident-sounding guide (my test "Rare Thing" did).
8. **Headers:** no Content-Security-Policy yet; Apache and PHP announce their versions (`expose_php` in php.ini).
9. **Health-status colours are reused** for form errors and "ok" messages, alerts, the Open / Exchanged badges and the
   High / Medium confidence badges (always with a word beside them). Giving these their own token names
   (`--color-success` …) would be a design change, so it is only noted.
10. **Docs are behind the code:** README says "Status 4 Oct" and "no database needed"; CLAUDE.md says stage B is
    starting; `api/check-username.php` mentions `register.js` (now `username-check.js`).
11. **A species typed for a "Not in my garden" listing that is not in our list is not stored** (the listing then has
    no species). Collection rename and privacy change are still not built (D4).
12. **Chrome prints a hint** (not an error) on Settings: the password forms have no username field for password managers.

## 7. Manual test checklist

Open <http://localhost/plantfolio/public/>. Demo login: `nimali.grows` (password in `database/README.md`).
Tick each line; write down anything that differs.

**Accounts**
- [ ] Sign up with a new username. The Dashboard opens with an empty garden.
- [ ] Open the account menu (top right) and press **Log out**. You land on the first page; `garden.php` now asks you to log in. *(BUG-03)*
- [ ] Type `…/public/garden.php` while logged out. The Log in page says "Log in to continue", not "show your interest". *(BUG-12)*
- [ ] Enter a wrong password 5 times: the 6th try says "Too many attempts".

**Plants**
- [ ] Add a plant and type a species that is not suggested, e.g. "Mystery fern". Open it, press **Edit**: the Species field still says "Mystery fern". Save: it is still there. *(BUG-02)*
- [ ] Add a plant with a photo taken on a phone held upright. The photo stands upright on the card and the profile. *(BUG-05)*
- [ ] Give a plant a nickname of 50 letters with no space. No page scrolls sideways, on the phone width too (F12 → phone icon → 375). *(BUG-08)*
- [ ] On a plant, press **Done** on a reminder, then **+ Add to story** → Watered, dated last week. The reminder's next date does not change. *(BUG-06)*

**Exchange**
- [ ] Add a plant **without** a photo and without a species, then **List for exchange**, fill the form and publish. You get a message asking for a photo, not an error page. *(BUG-01)*
- [ ] Publish a Free listing and leave "Looking for" empty. Its page says "Nothing, free to a good home". *(BUG-10)*
- [ ] With a second account: press **I'm interested**. Only now the owner's contact details appear.
- [ ] As the owner: My exchanges → Mark as exchanged → rate. The other member rates too. Neither can rate a second time.

**AI tools** (Flask and Ollama running)
- [ ] Health check a leaf, press **Save to story**, press the browser's Back button and Save again. The Story shows the diagnosis once. *(BUG-07)*
- [ ] Stop Flask (close its window), then Identify a plant: the page says it couldn't reach the Plant Identifier and offers to go on by hand.

**Account deletion**
- [ ] With a test account that has a plant photo: copy the photo's address (right click → Copy image address), then Settings → Delete account. Open the copied address: "Not Found". *(BUG-04)*
- [ ] On the profile of someone that account had reviewed, the review now says "Former member". *(BUG-09)*

**Security**
- [ ] `http://localhost/plantfolio/public/api/` and `…/public/assets/` answer **Forbidden**, not a file list. *(BUG-11)*
- [ ] `http://localhost/plantfolio/.git/config` and `…/config/config.php` answer **Not Found** / show nothing.
