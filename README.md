# Petanque Registration

Εφαρμογή δήλωσης αθλητών συλλόγων σε πρωταθλήματα πετάνκ (Ντουμπλέτες, Τριπλέτες, Μεικτό).

Γραμμένη σε **PHP 8.1+ / MySQL / HTML / JavaScript** χωρίς framework, έτοιμη για XAMPP.

---

## Δυνατότητες

- **Login συλλόγων** και ξεχωριστό **admin panel**.
- Κάθε σύλλογος βλέπει **μόνο τους δικούς του αθλητές** και δηλώνει ομάδες.
- **3 τύποι πρωταθλημάτων**:
  - Ντουμπλέτες (Άνδρες / Γυναίκες) — 2 παίκτες ίδιου φύλου
  - Τριπλέτες (Άνδρες / Γυναίκες) — 3 παίκτες ίδιου φύλου
  - Μεικτό Ντουμπλέτες — 1 άνδρας + 1 γυναίκα
- **Validations** σύνθεσης ομάδας server-side και client-side.
- **Deadline** ανά πρωτάθλημα — μετά τη λήξη κλειδώνει η φόρμα.
- **Export CSV** ομάδων / παικτών (με BOM για Excel).
- Συμβατότητα με υπάρχοντα πίνακες `games`, `games2`, `aa`, `teams` (ίδια ονοματολογία, ίδιο format `teamcode`, `playercodes` dash-separated).
- CSRF protection, PDO prepared statements, bcrypt passwords.

---

## Δομή φακέλων

```
petanque-registration/
├── config.php.example      # αντίγραψε σε config.php
├── sql/
│   ├── schema.sql          # CREATE TABLE ... IF NOT EXISTS (ασφαλές σε υπάρχουσα DB)
│   └── sample_data.sql     # δοκιμαστικά δεδομένα + users
├── public/                 # DocumentRoot (ή ολόκληρος φάκελος σε XAMPP/htdocs)
│   ├── index.php           # login
│   ├── logout.php
│   ├── assets/             # CSS/JS/layout
│   ├── club/               # σελίδες συλλόγου
│   └── admin/              # σελίδες διαχείρισης
└── src/                    # backend logic (db, auth, validators)
```

---

## Εγκατάσταση (XAMPP)

1. **Αντιγράψτε** τον φάκελο σε `C:\xampp\htdocs\petanque-registration` (ή όπου χρησιμοποιείτε).
2. **Δημιουργήστε βάση** (π.χ. μέσω phpMyAdmin):
   ```sql
   CREATE DATABASE petanque DEFAULT CHARSET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
3. **Τρέξτε το schema**:
   ```bash
   mysql -u root -p petanque < sql/schema.sql
   ```
   Αν έχετε **υπάρχοντες** πίνακες (games/games2/aa/teams/clubs/players) δεν χρειάζεται — οι `CREATE TABLE IF NOT EXISTS` δεν πατάνε τίποτα. Απλά τρέξτε μόνο τα `club_users` και `admins` blocks, ή χειροκίνητα:
   ```sql
   ALTER TABLE `games` ADD COLUMN IF NOT EXISTS `registration_deadline` DATETIME NULL;
   ```
4. **(Προαιρετικό)** Φορτώστε τα δοκιμαστικά δεδομένα:
   ```bash
   mysql -u root -p petanque < sql/sample_data.sql
   ```
5. **Αντιγράψτε το config**:
   ```bash
   cp config.php.example config.php
   ```
   και προσαρμόστε τα στοιχεία σύνδεσης στη βάση (host, user, password, dbname). Αν οι πίνακές σας έχουν διαφορετικά ονόματα, αλλάξτε τα κλειδιά `tables`.

6. **Ανοίξτε** στον browser: `http://localhost/petanque-registration/public/`

---

## Δοκιμαστικοί λογαριασμοί (μετά από `sample_data.sql`)

| Ρόλος        | Username | Password   |
|--------------|----------|------------|
| Admin        | `admin`  | `admin1234` |
| Σύλλογος ΓΑΛ | `gal`    | `test1234`  |
| Σύλλογος ΠΕΙΡ| `peir`   | `test1234`  |
| Σύλλογος ΜΑΡ | `mar`    | `test1234`  |
| Σύλλογος ΘΕΣ | `thes`   | `test1234`  |
| Σύλλογος ΠΕΤ | `peta`   | `test1234`  |
| Σύλλογος ΠΡΑ | `pramp`  | `test1234`  |

Αλλάξτε τους κωδικούς στο production.

---

## Κανόνες δηλώσεων

- Κάθε **αθλητής** μπορεί να δηλωθεί σε **μία μόνο ομάδα** ανά πρωτάθλημα.
- **Απεριόριστος** αριθμός ομάδων ανά σύλλογο.
- **Ονοματοδοσία ομάδων** (`teams.teamname`): π.χ. `GAL1`, `GAL2`, `GAL1w`, `GAL2w`, `GALmix1`. Το `w` δηλώνει γυναικεία, το `mix` μεικτή.
- **`teamcode` στο `games2`**: `ath1..athN` (άνδρες), `ath1w..athNw` (γυναίκες), `mix1..mixN` (μεικτό).
- **`playercodes` στο `teams`**: dash-separated, π.χ. `000002-000021` (Doubles) ή `000002-000021-000019` (Triples).
- **Deadline**: ορίζεται από admin στο πεδίο `games.registration_deadline`. Αν είναι NULL, η φόρμα μένει ανοιχτή όσο το `status='Y'`.

---

## Διαχείριση

Πλοήγηση από το admin panel (`/admin/`):

- **Πρωταθλήματα** — CRUD, toggle κατάστασης (Ενεργό/Ανενεργό), προθεσμία δηλώσεων.
- **Σύλλογοι** — CRUD.
- **Λογαριασμοί** — δημιουργία/απενεργοποίηση username/password για κάθε σύλλογο.
- **Αθλητές** — προβολή/φιλτράρισμα όλων των αθλητών.
- **Όλες οι δηλώσεις** — προβολή ανά πρωτάθλημα.
- **Εξαγωγή CSV** — ομάδες ή παίκτες ανά πρωτάθλημα.

---

## Ασφάλεια

- CSRF tokens σε όλες τις POST φόρμες.
- `password_hash` / `password_verify` (bcrypt) για όλους τους κωδικούς.
- PDO prepared statements για όλα τα queries.
- HTML escape (`h()`) σε όλη την έξοδο.
- Cookies `HttpOnly` + `SameSite=Lax`.

---

## Local development με PHP built-in server

```bash
php -S localhost:8000 -t public/
```

Στον browser: <http://localhost:8000/>.

---

## Deployment σε Hostinger (shared hosting με cPanel / hPanel)

Η εφαρμογή τρέχει σε κάθε shared hosting με PHP 8.1+ και MySQL. Βήματα για Hostinger:

### 1. Δημιουργία βάσης δεδομένων

1. Στο hPanel → **Databases** → **MySQL Databases** → **Create New Database**.
2. Σημείωσε **database name**, **user**, **password** — θα τα βάλεις στο `config.php`.
3. Κάνε κλικ **phpMyAdmin** δίπλα στη DB που μόλις έφτιαξες.
4. Στο phpMyAdmin → tab **Import** → ανέβασε διαδοχικά:
   - `sql/schema.sql`
   - `sql/sample_data.sql` (προαιρετικό — για δοκιμαστικά δεδομένα)
5. Αν **υπάρχουν ήδη** οι πίνακες `games`/`games2`/`aa`/`teams`/`clubs`/`players` από το παλιό σου σύστημα, τρέξε μόνο:
   ```sql
   ALTER TABLE `games` ADD COLUMN IF NOT EXISTS `registration_deadline` DATETIME NULL;
   ```
   και ό,τι `CREATE TABLE IF NOT EXISTS` αφορά `club_users` και `admins`.

### 2. Upload αρχείων

**Επιλογή Α — File Manager (χωρίς FTP client):**

1. hPanel → **Files** → **File Manager** → άνοιξε τον φάκελο `public_html/`.
2. Upload ένα `.zip` με όλα τα περιεχόμενα του repo και κάνε **Extract** μέσα στο `public_html/`.
3. **Document root = `public/`**. Επειδή στο Hostinger το document root είναι το `public_html/`, έχεις δύο επιλογές:
   - **Συνιστώμενο**: μετακίνησε τα περιεχόμενα του `public/` **απευθείας μέσα** στο `public_html/` (δηλ. `public_html/index.php`, `public_html/assets/…`, `public_html/club/…`, `public_html/admin/…`), και τους φακέλους `src/`, `sql/`, και το `config.php` βάλε τα **έξω** από το `public_html/` (π.χ. στο `/home/<user>/petreg/`). Μετά ενημέρωσε το `src/bootstrap.php` ώστε να κάνει `require __DIR__.'/../../petreg/config.php';` (ή όπου το έβαλες).
   - **Γρήγορο** (λιγότερο ασφαλές — ο `src/` είναι μέσα στο `public_html/` αλλά δεν σερβίρεται γιατί δεν έχει entry points): άφησε όλη τη δομή όπως είναι και φτιάξε subdomain (`petreg.yourdomain.gr`) με document root `public_html/petanque-registration/public/`.

**Επιλογή Β — FTP:**

1. hPanel → **Files** → **FTP Accounts** → πάρε credentials.
2. Χρησιμοποίησε FileZilla κλπ και ανέβασε με ίδια δομή όπως παραπάνω.

### 3. Config

1. Αντίγραψε `config.php.example` → `config.php`.
2. Επεξεργάσου:
   ```php
   'db' => [
       'host'     => 'localhost',   // συνήθως localhost στο Hostinger
       'dbname'   => 'uXXX_petanque',
       'user'     => 'uXXX_petuser',
       'password' => '…',
       'charset'  => 'utf8mb4',
   ],
   ```
3. Ρύθμισε PHP version: hPanel → **Advanced** → **PHP Configuration** → επίλεξε **PHP 8.1 ή νεότερη**.

### 4. URL rewriting (προαιρετικό)

Η εφαρμογή δεν απαιτεί rewriting — όλα τα URLs είναι `.php` direct. Αν θες να κρύψεις το `.php` extension, δημιούργησε `public_html/.htaccess`:

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^([^\.]+)$ $1.php [NC,L]

# αποτροπή πρόσβασης σε src/ αν δεν το μετακίνησες έξω από public_html
RewriteRule ^src/ - [F,L]
RewriteRule ^sql/ - [F,L]
RewriteRule ^config\.php$ - [F,L]
```

### 5. SSL

hPanel → **Security** → **SSL** → ενεργοποίησε το δωρεάν **Let's Encrypt** για το domain σου. Η εφαρμογή χρησιμοποιεί session cookies με `Secure=true` όταν το request είναι HTTPS.

### 6. Πρώτο login & αλλαγή κωδικών

- Πήγαινε στο `https://<domain>/` (ή `/petanque-registration/public/` ανάλογα με το setup).
- Login ως `admin`/`admin1234` → άλλαξε password άμεσα.
- Δημιούργησε νέους λογαριασμούς συλλόγων ή άλλαξε τους default (`gal`, `peir`, κλπ).

### Troubleshooting

- **"Internal Server Error"**: συνήθως λάθος PHP version. Βεβαιώσου ότι είναι ≥ 8.1.
- **"SQLSTATE[HY000] [1049] Unknown database"**: λάθος `dbname` στο `config.php`.
- **Greek characters σαν `???`**: βεβαιώσου ότι η DB/tables είναι σε `utf8mb4_unicode_ci` και ότι το connection charset είναι `utf8mb4` (ήδη ρυθμισμένο στο `src/db.php`).
- **File Manager δεν δείχνει hidden files (`.htaccess`)**: settings → "Show hidden files".
