# PlantFolio 🌿
*Where your plants tell their story.*

PlantFolio is a mobile-responsive web application for plant lovers. Every plant gets its own profile with a dated
story, care reminders tell you what needs water today, and a community exchange lets you swap or give away plants
with people nearby. Three AI helpers identify a plant from a photo, suggest what might be wrong with a sick leaf,
and write a care guide for any species.

**Live demo:** <https://plantfolio.liveblog365.com/public/> (the AI tools run only on a local install, see [below](#ai-tools))

Built with plain PHP, MySQL and vanilla JavaScript – no frameworks – as coursework for **CMIS 3124 Web Designing and
E-Commerce**, B.Sc. (Hons) in Computer Science, Wayamba University of Sri Lanka.

---

## Features

**Your garden**
- Sign up with a live username check, log in with username or email, "Remember me" for 30 days
- My Garden: grid or list view, search, health filter, sorting, collections (public or private), plants of the same species stacked into one card
- Plant profiles with a photo gallery, quick facts, reminders and a **Story** timeline (notes, photos, care done, health checks, milestones)
- Care reminders like a phone alarm (once, daily, chosen weekdays or every N days), a Dashboard of what is due today or overdue, **Done** with Undo

**Community and exchange**
- One Community page with three tabs – Exchange, Plants and People – with search, filters (type, category, light, water, species, rating) and a distance filter based on the nearest town
- Exchange listings (swap or free) with photos and categories
- "I'm interested" requests: the owner's email or phone appear only after you show interest, and only the details the owner chose to share
- Mark a listing as exchanged, then both sides rate each other; ratings and reviews appear on public profiles

**AI tools** ✦
- **Plant Identifier** – upload a photo, get the three most likely species with a confidence level, then add the plant to your garden in one click
- **Health Check** – upload a leaf photo, get the likely disease or pest with what it looks like and what to do, save the result to the plant's story and set a 7-day re-check
- **AI care guide** – light, soil and feeding, watering, disease control and pruning for each species, written by a local language model once and saved for everyone

The AI only suggests: nothing is saved until you press Save, and below 60% confidence it says "Not sure" instead of guessing.

## Tech stack

| Layer | Technology |
|---|---|
| Front end | HTML5, hand-written CSS with design tokens (custom properties, no CSS framework), vanilla JavaScript (ES6, `fetch()`), mobile-first from 375 px |
| Back end | PHP 8 (no framework), one file per page, shared logic in `includes/`, reusable UI in `partials/` |
| Database | MySQL 8 / MariaDB 10.4+ (InnoDB, utf8mb4) through PDO prepared statements – 18 tables, 26 foreign keys |
| ML service | Python 3.10+ and Flask, forwarding photos to the [Pl@ntNet API](https://my.plantnet.org) |
| Language model | [Ollama](https://ollama.com) with `llama3.2` running locally (no API key) |
| Fonts and icons | Lora and Poppins (Google Fonts), [Lucide](https://lucide.dev) icons |
| Server | Apache (XAMPP locally) |

## How it works

```mermaid
flowchart LR
    B[Browser<br/>HTML · CSS · JS] -- HTTP --> P[PHP pages<br/>public/*.php]
    P -- PDO --> D[(MySQL<br/>18 tables)]
    P -- saves --> U[public/uploads/]
    P -- cURL --> F[Flask ML service<br/>127.0.0.1:5000]
    F -- HTTPS --> N[Pl@ntNet API]
    P -- cURL --> O[Ollama LLM<br/>127.0.0.1:11434]
```

- Pages are rendered on the server; JavaScript only adds no-reload extras (live username check, Community filters, AI text loading). Everything still works without JavaScript.
- The browser only ever talks to PHP. PHP sends photos to the Flask service, validates the JSON answer and stores every result in `ml_predictions`.
- If Flask or Ollama is not running, the pages say so and offer the manual way – the site never breaks because of the AI.

## Security

- PDO prepared statements only (real prepares, strict SQL mode)
- Every value printed into HTML is escaped with `e()` (`htmlspecialchars`); AI text is inserted with `textContent`
- Passwords hashed with `password_hash()`; one generic login error; account locked for 15 minutes after 5 wrong passwords
- Session cookie HttpOnly and SameSite, session id regenerated at login; "Remember me" tokens stored only as SHA-256 hashes
- CSRF token on every POST form (log out included)
- Ownership checks inside every SQL write; private plants answer "not found" to everyone else
- Uploads checked by their real file type (`finfo`, JPG/PNG only), 5 MB limit, random names, re-drawn and resized; PHP disabled in the uploads folder
- Only `public/` is served; `config/`, `includes/`, `database/` and `.git` are blocked by `.htaccess`

The full test report – 449 scripted checks, page sweeps at three screen widths, 16 bugs found and fixed – is in [`QA_REPORT.md`](QA_REPORT.md).

## Getting started

Full steps, including free online hosting: **[`docs/SETUP.md`](docs/SETUP.md)**. In short:

1. Install [XAMPP](https://www.apachefriends.org) (PHP 8.1+) and put the project in the web folder:
   ```bash
   cd C:/xampp/htdocs
   git clone https://github.com/rSith/<this-repository>.git plantfolio
   ```
2. Start **Apache** and **MySQL**. In phpMyAdmin create a database called `plantfolio` (collation `utf8mb4_unicode_ci`) and import `database/schema.sql`, then `database/seed.sql`.
3. Copy `config/config.example.php` to `config/config.php` (the defaults fit a standard XAMPP install).
4. Enable the GD extension in `php.ini` (`extension=gd`) so photos are resized, and restart Apache.
5. Open <http://localhost/plantfolio/> and log in as `nimali.grows` with the demo password listed in [`database/README.md`](database/README.md).

The demo data contains five sample members with plants, reminders, listings, ratings and saved care guides. Re-importing `seed.sql` resets it.

### AI tools

The AI tools need two extra services on the same computer. Without them the rest of the site works normally.

| Tool | Needs | Setup |
|---|---|---|
| Plant Identifier, Health Check | Python 3.10+ and a free [Pl@ntNet API key](https://my.plantnet.org) | `cd ml-service` → `python -m venv venv` → activate it → `pip install -r requirements.txt` → copy `.env.example` to `.env` and add your key → `python app.py` (port 5000). Details: [`ml-service/README.md`](ml-service/README.md) |
| AI care guide | [Ollama](https://ollama.com) | `ollama pull llama3.2` and keep Ollama running, or set another installed model in `config/config.php` (`llm_model`) |

Free PHP hosting cannot run Python or Ollama, so on the live demo the Identifier and Health Check show their "couldn't reach" state; care guides that were already generated still appear.

## Project structure

```
plantfolio/
├── public/          # Web root: one PHP file per page
│   ├── api/         #   JSON endpoints for fetch() (username check, AI text)
│   ├── assets/      #   css/ (design tokens, components, one file per page) · js/ · img/
│   └── uploads/     #   member photos (not in Git)
├── includes/        # Shared PHP: helpers, db, auth, uploads, data functions, ML and LLM clients
├── partials/        # Reusable UI pieces (plant-card.php, listing-card.php …)
├── config/          # config.example.php (config.php with real credentials is not in Git)
├── database/        # schema.sql, seed.sql, data dictionary
├── ml-service/      # Flask API for the Plant Identifier and Health Check
└── docs/            # Setup guide, proposal, wireframes, database design, decisions
```

| Page | File |
|---|---|
| Landing | `index.php` |
| Sign up · Log in | `register.php`, `login.php` |
| Dashboard | `dashboard.php` |
| My Garden | `garden.php` |
| Plant profile · Add/Edit plant | `plant.php`, `plant-form.php` |
| Plant Identifier · Health Check | `identify.php`, `health-check.php` |
| Community (Exchange, Plants, People) | `community.php` |
| Listing · Create listing · My exchanges | `listing.php`, `listing-form.php`, `my-exchanges.php` |
| Public profile · Settings | `user.php`, `settings.php` |
| About, guidelines, contact | `about.php` |

## Design

The design system, wireframes and user flows are in [`docs/PlantFolio_Wireframes_UX_Structure.pdf`](docs/PlantFolio_Wireframes_UX_Structure.pdf), with a clickable [Figma prototype](https://www.figma.com/design/el5WGQz2oTerXiAfFiKrPS). Colours, fonts and spacing live as CSS custom properties in `public/assets/css/variables.css`; health status is always shown with a word, never colour alone.

## Known limitations

- The Health Check uses the Pl@ntNet disease API; its scores are low for many photos, so results are shown as "Possible match" or "Not sure". A self-trained disease model (MobileNetV2 on PlantVillage) is planned; the Flask service can already load one (`DISEASE_PROVIDER=model`).
- No rate limiting beyond the login lockout, no Content-Security-Policy header yet, and no email reminders or password reset.
- Collections cannot be renamed yet.

More detail in section 6 of [`QA_REPORT.md`](QA_REPORT.md).

## Author

**Ranshitha Attanayake** – B.Sc. (Hons) in Computer Science, Wayamba University of Sri Lanka · [GitHub @rSith](https://github.com/rSith)

Icons by [Lucide](https://lucide.dev) (ISC licence); other credits in [`docs/credits.md`](docs/credits.md).
