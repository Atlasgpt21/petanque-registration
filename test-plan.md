# Test Plan — Petanque Registration PR #1

## What changed (user-visible)
New PHP/MySQL web app for petanque clubs to declare teams for upcoming championships. Includes club portal (login → declare Doubles/Triples/Mixed teams with gender-aware validation) and admin portal (manage championships, clubs, users, view all declarations, CSV export). Compatible with existing `games`/`games2`/`aa`/`teams` schema.

## Primary end-to-end flow
**"A club can declare a Doubles Α team and the admin sees it + exports it correctly in CSV."**

This single flow exercises: login (club side), championship fetch, team builder validation (category M filters to 8 male players), atomic writes to `teams`+`games2`+`aa`, teamname/teamcode generation (`GAL1`, `ath1`/`ath2`), admin login + view, and CSV export with Greek UTF-8.

## Setup preconditions (not part of the plan — already done)
- PHP built-in server running at `http://localhost:8000`
- DB `petanque` loaded from `sql/schema.sql` + `sql/sample_data.sql`
- Championship `222A` (Doubles) is active with deadline `2026-04-28 23:59:59` (in the future).
- GAL club has 8 male + 4 female sample players (verified via DB query).
- No existing team for GAL in 222A (DB state clean).

---

## Tests

### T1 — Club login (gal / test1234)
- **Action**: Navigate to `http://localhost:8000/`, click "Σύλλογος" tab, enter `gal` / `test1234`, submit.
- **Expected (passes only if all true)**:
  - Redirects to `/club/dashboard.php` (URL contains `/club/dashboard.php`).
  - Page shows the club name from DB: **"ΑΟ ΓΑΛΑΞΙΑ"** (not a generic placeholder, not empty).
  - Active championship card shows code `222A`, name `ΠΑΝΕΛΛΗΝΙΟ ΠΡΩΤΑΘΛΗΜΑ 2vs2 25-26`, and a "Δήλωση Ομάδων" button (button is enabled because deadline is in the future).
- **Would this pass if the change were broken?** No — a broken login would stay on `/index.php` with an error, a broken session would lose `clubname`, a broken deadline check would disable the button.

### T2 — Team builder validates gender & composition
- **Action**: Click the "Δήλωση Ομάδων" button for 222A. On the teams page, click "Νέα ομάδα". Leave category = "Άνδρες" (M). Try to submit the form with zero players selected.
- **Expected**:
  - The submit button is **disabled** until exactly 2 players are selected (inspect via DOM or click-and-observe: nothing happens).
  - Selecting the first male player (e.g. `Παπαδόπουλος Γιώργος`) enables further selection; selecting a second (e.g. `Αντωνίου Νίκος`) enables the submit button.
  - Attempting to check a third male player is **blocked** (checkbox does not stick / a "σύνολο 2/2" indicator shows full).
  - The list shows **8 male players** when category = M. If I switch category to "Γυναίκες" (F), the list shows **4 female players** (and the previously-selected males are cleared).
- **Would this pass if broken?** No — a broken JS filter would show all 12 players or show opposite gender; a broken max-check would allow 3+; a broken submit-guard would allow 0-player POSTs.

### T3 — Team persists to DB with correct teamname/teamcode
- **Action**: With category M and 2 male players selected (`Παπαδόπουλος Γιώργος` 000002 + `Αντωνίου Νίκος` 000021), click "Αποθήκευση".
- **Expected**:
  - Page redirects back to `/club/teams.php?g=222A` with a green flash message containing "αποθηκεύτηκε" or "Δημιουργήθηκε".
  - The team list now shows **1 team** with teamname `GAL1`, category badge "Άνδρες", and players "Παπαδόπουλος Γιώργος (000002)" and "Αντωνίου Νίκος (000021)".
  - Shell verification (I'll run `mysql -e "SELECT ..."` after UI step):
    - `SELECT teamname, playercodes, category FROM teams WHERE clubcode='000003' AND gamecode='222A'` → exactly **1 row**: `GAL1 | 000002-000021 | M`
    - `SELECT playercode1, teamcode FROM games2 WHERE clubcode='000003' AND gamecode='222A' ORDER BY teamcode` → exactly **2 rows**: `000002|ath1`, `000021|ath2`
    - `SELECT count(*) FROM aa WHERE clubcode='000003' AND gamecode='222A'` → **1** (auto-created)
- **Would this pass if broken?** No — any of these assertions would fail distinctly if insert logic / teamname generation / aa auto-create is broken.

### T4 — Admin login + sees GAL1 team
- **Action**: Open new tab, go to `http://localhost:8000/`, click "Διαχειριστής" tab, enter `admin`/`admin1234`, submit. Navigate to "Όλες οι Δηλώσεις".
- **Expected**:
  - Dashboard shows stats: **Σύλλογοι 6, Αθλητές 22, Πρωταθλήματα 3, Ομάδες 1** (Ομάδες=1 is the key assertion — the team from T3 must appear in the aggregate count).
  - On `/admin/all_teams.php` with 222A selected, the table shows exactly **1 row**: Σύλλογος "ΑΟ ΓΑΛΑΞΙΑ / 000003", Ομάδα `GAL1`, Κατηγορία "Άνδρες", Παίκτες listing both names with codes.
- **Would this pass if broken?** No — if admin session is wrong, or if teams query is broken, the row won't appear or counts will be wrong.

### T5 — CSV export has correct Greek content
- **Action**: Click "Εξαγωγή" in sidebar, then click "CSV — Ομάδες" for championship 222A. Save the downloaded file to disk and inspect.
- **Expected (shell verification via `head /tmp/downloaded.csv | iconv -f UTF-8 -t UTF-8 -c`)**:
  - First 3 bytes are the UTF-8 BOM `EF BB BF`.
  - Header row contains Greek column titles: `Πρωτάθλημα,Σύλλογος,Κωδ. Συλλόγου,Ομάδα,Κατηγορία,Πλήθος,Κωδικοί Παικτών,Παίκτες`
  - Exactly **1 data row** containing: `222A`/`ΠΑΝΕΛΛΗΝΙΟ ΠΡΩΤΑΘΛΗΜΑ 2vs2 25-26` … `GAL1` … `Άνδρες` … `2` … `000002-000021` … both player names.
- **Would this pass if broken?** No — missing BOM would break Excel encoding visibly; wrong row count or missing columns would fail the exact-match assertion.

---

## Out of scope (explicitly NOT testing in this run)
- Triples and Mixed championships (not active in sample data; tested implicitly by shared code paths — would be separate regression test).
- Deadline-locked form (no active championship with past deadline in sample data; covered by code review of `is_registration_open()`).
- Athlete CRUD, club CRUD, club_users CRUD (admin housekeeping — not the core registration flow).
- Password change on first login.

## Files/lines informing this plan
- Login + session: `public/index.php`, `src/auth.php`
- Club dashboard + deadline gate: `public/club/dashboard.php`, `src/validators.php` (`is_registration_open`)
- Team builder UI + validation: `public/club/teams.php:68-180`, `public/assets/app.js`
- Admin view: `public/admin/dashboard.php`, `public/admin/all_teams.php:19-26`
- CSV export: `public/admin/export.php`
