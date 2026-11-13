# PlantFolio: setup guide

Two ways to run PlantFolio:

- **[A. On your own computer with XAMPP](#a-on-your-own-computer-xampp).** Everything works, including the AI tools.
- **[B. Online on ProFreeHost](#b-online-on-profreehost-free-hosting).** Free hosting. The website works; the three AI
  tools do not (see [what works online](#what-works-online)).

**Demo login (both ways):** username `nimali.grows`, password `PlantFolio#2026`. The other sample members
(`aroid.amaya`, `kandy.jungle`, `dinesh.greens`, `ruwan.roots`) use the same demo password. Anyone who reads this guide
knows it, so treat the demo accounts as public.

**What is in the package:**

| Folder | What it is |
|---|---|
| `public/` | The website itself: one PHP file per page, styles, scripts, images |
| `includes/`, `partials/` | Shared PHP code and page pieces (never opened directly by the browser) |
| `config/` | `config.example.php`, the settings template. You make your own `config.php` from it |
| `database/` | `schema.sql` (the 18 tables) and `seed.sql` (the demo data) |
| `ml-service/` | The Python Flask service for the Plant Identifier and Health Check (computer only) |
| `docs/` | Proposal, wireframes, database design, roadmap, build notes, this guide |
| `QA_REPORT.md` | The test report |

The package holds no passwords, API keys, member photos or database dumps.

---

## A. On your own computer (XAMPP)

**You need:** [XAMPP](https://www.apachefriends.org) with PHP 8.1 or newer (Apache and MySQL/MariaDB).

1. **Copy the files.** Right-click `plantfolio.zip` → **Extract All** → extract to `C:\xampp\htdocs\plantfolio`, so that
   you have `C:\xampp\htdocs\plantfolio\public\…`. The folder must be called `plantfolio`, or change `base_url` in step 3.
2. **Make the database.**
   1. Start **Apache** and **MySQL** in the XAMPP Control Panel.
   2. Open <http://localhost/phpmyadmin>, then **New** → name `plantfolio` → collation `utf8mb4_unicode_ci` → **Create**.
   3. With `plantfolio` selected: **Import** → `database/schema.sql` → **Import**. Then **Import** → `database/seed.sql` → **Import**.
3. **Make your settings file.** Copy `config/config.example.php` to `config/config.php`. The defaults fit a normal XAMPP
   (user `root`, no password). Change nothing else for now.
4. **Switch on GD**, the image library that resizes photos.
   1. Open `C:\xampp\php\php.ini` and remove the `;` in front of `extension=gd`.
   2. Restart Apache.
5. **Open the site:** <http://localhost/plantfolio/> (it forwards to `/plantfolio/public/`). Log in as `nimali.grows`.

### The AI tools (optional, computer only)

| Tool | Needs | How |
|---|---|---|
| Plant Identifier, Health Check | Python 3.10+ and a free [Pl@ntNet API key](https://my.plantnet.org) | See [`ml-service/README.md`](../ml-service/README.md): create `ml-service/venv`, `pip install -r requirements.txt`, copy `.env.example` to `.env`, put your key in it, run `python app.py`. It listens on port 5000 |
| AI care guide | [Ollama](https://ollama.com) | Install Ollama, run `ollama pull llama3.2`. Or use a model you already have and set `llm_model` in `config/config.php` (e.g. `'llama3:latest'`) |

Without them the site still works: those pages say the service can't be reached and offer the manual way.

### Reset the demo data

Import `database/seed.sql` again. It empties **every** table first, so accounts you made yourself are deleted too.

---

## B. Online on ProFreeHost (free hosting)

ProFreeHost gives PHP, MySQL, phpMyAdmin and a file manager for free (5 GB space, about 50,000 hits a day). You do
every step yourself in your browser; nobody else needs your password.

### What works online

| Works | Does not work online |
|---|---|
| Sign up, log in, garden, collections, plant profiles, Story, reminders, Community search, exchange listings, interest, ratings, settings, photo uploads | **Plant Identifier** and **Health Check**: they need the Python Flask service, and free hosting runs PHP only. **AI care guide**: it needs Ollama on your computer. These pages show "We couldn't reach…" and the manual way, as designed. Saved care guides from the demo data still show |

### Step 1: account and website

1. Sign up at <https://profreehost.com> and confirm your email.
2. In the client area, **create a hosting account** and pick a free subdomain, e.g. `plantfolio.<their-domain>`.
   Wait until the account is active; it can take a few minutes.
3. Open the **control panel** of that hosting account.

### Step 2: database

1. In the control panel, open **MySQL Databases**, create a database (e.g. `plantfolio`) and note the four values it
   shows: **host name**, **database name** (it gets a prefix, like `pfh_12345678_plantfolio`), **user name** and
   **password**.
2. Click **phpMyAdmin** next to the new database.
3. **Import** → `database/schema.sql` → **Go**. Then **Import** → `database/seed.sql` → **Go**.
   Both should end with a green "Import has been successfully finished".

> If `schema.sql` fails with an error about `ROW_NUMBER`, `CHECK` or `JSON`, the server runs an old MySQL (5.7). The
> site needs MySQL 8.0+ or MariaDB 10.4+: choose another server version if the panel offers one, or ask ProFreeHost.

### Step 3: upload the files

1. In the control panel, open the **File Manager** and go into the `htdocs` folder.
2. Delete the host's welcome files in `htdocs` (for example `index2.html`, `default.php`).
3. **Upload** the zip into `htdocs` and **Extract** it there. If the file manager cannot extract zips, unzip it on your
   computer and upload the folders with an FTP program (FileZilla), using the FTP details in the control panel.
4. Check that `htdocs` now holds `public`, `includes`, `partials`, `config`, `database` … directly (not inside an extra
   `plantfolio` folder), and that `htdocs/.htaccess` and `htdocs/public/.htaccess` are there. They start with a dot, so
   switch on "show hidden files" if you don't see them.

### Step 4: the settings file

In the File Manager, copy `config/config.example.php` to `config/config.php`, open it and change these lines
(keep everything else):

```php
'app_env'  => 'production',                                  // hides PHP errors from visitors
'base_url' => 'https://your-subdomain.example.com/public',   // your site's address + /public, no / at the end

'db_host' => 'sqlXXX.example.com',          // the four values from step 2
'db_name' => 'pfh_12345678_plantfolio',
'db_user' => 'pfh_12345678',
'db_pass' => 'the database password',
```

`base_url` starts with `https://` only if your site opens with https (the padlock). If it doesn't, use `http://`.

Leave the `ml_service_url` and `llm_url` lines as they are: online they simply can't be reached.

### Step 5: open it

1. Open your site's address. It forwards to `/public/` and shows the PlantFolio landing page.
2. Log in as `nimali.grows`.
3. Add a plant with a photo, so you know uploads work.

### If something goes wrong

| What you see | What to do |
|---|---|
| "Sorry, PlantFolio is having trouble right now" | The database settings in `config/config.php` are wrong. Copy the four values again from **MySQL Databases**. The database password is the **hosting account** password (client area → Manage → account details), not the one you log in to profreehost.com with. To see the exact reason, set `app_env` to `'development'` for one page load, then set it back |
| File Manager says "Failed to load file: Could not read file" | Open the folder first (double-click `config`), then edit the file from inside it, not from the expanded tree |
| Page without colours or styles, or links to `localhost` | `base_url` is wrong: it must be your site's address followed by `/public` |
| **500 Internal Server Error** on every page | The host refused a line in `public/.htaccess`. Rename that file to `_htaccess` and try again; note that folder listings are then visible |
| 404 on every page | The files are inside an extra folder (`htdocs/plantfolio/…`). Move them up into `htdocs`, or add `/plantfolio` to `base_url` |
| "This form has expired" | Refresh the page and send the form again (the session ran out) |
| Photos upload but are not resized | The host has no GD. Photos are still checked and saved, only not made smaller |
| Error 508 "Resource limit reached" | Free hosting limits; wait a few minutes |

### Keep it safe

- `config/config.php` holds the database password. Never upload it to GitHub or send it to anyone.
- To reset the online demo data, import `database/seed.sql` again in phpMyAdmin. It deletes everything,
  including members who signed up online.
