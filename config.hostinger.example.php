<?php
/**
 * Παράδειγμα config για Hostinger (integration με υπάρχουσα βάση ΕΟΠ).
 *
 * Αντιγράψε σε config.php, συμπλήρωσε το password της MySQL user και ανέβασε
 * ΕΞΩ από το public_html για ασφάλεια (πχ /home/u197488276/private/config.php)
 * — ο bootstrap το φορτώνει από το APP_ROOT, δηλ. τον φάκελο που περιέχει
 * τα src/ + public/.
 */

return [
    'db' => [
        'host'     => 'localhost',
        'port'     => 3306,
        'dbname'   => 'u197488276_hpf',
        'user'     => 'u197488276_eoed',
        'password' => 'ΒΑΛΕ_ΕΔΩ_ΤΟΝ_ΚΩΔΙΚΟ',
        'charset'  => 'utf8mb4',
    ],

    // Mapping από τα logical ονόματα του κώδικα → πραγματικοί πίνακες/views
    // στη βάση της ΕΟΠ. Δημιουργούνται από το sql/migration_hostinger.sql.
    'tables' => [
        // Reads πάνω σε υπάρχοντα — όλα μέσω VIEWs (καμία αλλαγή δεν γίνεται).
        'games'      => 'games_v',        // UNION games + app_games + app_game_meta
        'app_games'  => 'app_games',      // writable table για νέα πρωταθλήματα
        'clubs'      => 'clubs_v',        // VIEW clubs (mitroo AS clubcode, …)
        'players'    => 'players_v',      // VIEW sportsmen (name AS firstname, …)
        'users'      => 'users_v',        // VIEW users — υπάρχοντες λογαριασμοί συλλόγων

        // Writes γίνονται ΜΟΝΟ σε νέους (app_*) πίνακες — κανένας υπάρχων
        // πίνακας δεν αγγίζεται.
        'games2'     => 'app_games2',
        'aa'         => 'app_aa',
        'teams'      => 'app_teams',
        'club_users' => 'app_club_users',
        'admins'     => 'app_admins',
    ],

    // Όνομα του πίνακα με registration_deadline metadata (δεν είναι logical
    // table του κώδικα, χρησιμοποιείται από admin/championships.php).
    'game_meta_table' => 'app_game_meta',

    'app' => [
        'name'         => 'Petanque Registration',
        'timezone'     => 'Europe/Athens',
        'session_name' => 'PETREG',
    ],

    // Όταν true: κρύβει/απενεργοποιεί τα UI που θα έγραφαν στους clubs/players
    // (τα υπάρχοντα δεδομένα διαχειρίζονται εκτός module).
    'readonly_external_data' => true,

    // Κρυπτογράφηση (AES-256-CTR) που χρησιμοποιεί το υπάρχον σύστημα της ΕΟΠ
    // σε κάποιες στήλες των clubs/sportsmen. Το module αποκρυπτογραφεί on-read
    // πριν την προβολή. ΠΟΤΕ δεν γράφει encrypted δεδομένα σε αυτούς τους
    // πίνακες (είναι read-only).
    'crypto' => [
        'enabled' => true,
        'method'  => 'AES-256-CTR',
        'key'     => 'helleniquepetanquefederation',
        'iv'      => '1234567891011121',
        // Ποιες στήλες είναι encrypted στα logical tables.
        'columns' => [
            'clubs'   => ['name', 'city'],
            'players' => ['firstname', 'lastname'],
            // users: encrypted fields στον υπάρχοντα πίνακα `users`.
            // Το `password` δεν μπαίνει εδώ — χειρίζεται ειδικά στο auth.php
            // (μπορεί να είναι AES-CTR ή bcrypt, ανάλογα με το αν ο χρήστης
            // έχει ήδη μπει μια φορά στην άλλη εφαρμογή).
            'users'   => ['username', 'lvl', 'type', 'status', 'name'],
        ],
    ],
];
