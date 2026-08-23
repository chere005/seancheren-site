<?php
/**
 * Sample config. Copy to lib/config.php (which is gitignored) and fill in.
 *
 *   cp lib/config.sample.php lib/config.php
 *
 * This file lives OUTSIDE the web root (public/), so it is never served.
 */

return [
    // --- Logins for the gated areas. Each user gets separate reminder data. ---
    // BCRYPT HASHES ONLY — a plaintext value here authenticates nobody.
    // Make one with:  php -r "echo password_hash('the password', PASSWORD_DEFAULT), PHP_EOL;"
    // Format: 'username' => '$2y$...'.
    'users' => [
        'admin' => 'changeme',
    ],

    // --- Secrets: keep these private, never commit lib/config.php ---
    'nfsn_member_password' => '',   // your NearlyFreeSpeech account password
    'nfsn_api_key'         => '',   // NFSN control panel -> Profile -> API key

    // Where reminder data is stored (outside the web root).
    'data_dir' => __DIR__ . '/../data',

    // URL prefix this instance's links are built under. '' is production (served at
    // the site root). The /test/ and /dev/ sandboxes set this to '/test' / '/dev' in
    // their own lib-test/ and lib-dev/ config.php, so every cross-app link (tab bar,
    // login landing, widget) stays inside that instance. Leave it '' here. See
    // deploy.sh's test/dev/promote modes.
    'base' => '',

    // The session cookie's name. All three instances share one domain, so a cookie at
    // path '/' reaches every one of them — the *name* is what keeps their logins apart,
    // and being signed into production must not sign you into /test/ or /dev/. Leave
    // this unset and it's derived from 'base' (SCSESS, SCSESS_TEST, SCSESS_DEV), which
    // is already distinct per instance; set it only to pin a particular name.
    // 'session_name' => 'SCSESS',

    // --- Outgoing mail (the sign-up verification code) ---
    // The smtp_* keys are read by nothing while mail_send() is stubbed — see lib/mail.php.
    // Without smtp_host the code goes out through PHP's mail(), which a shared host
    // sends unauthenticated and spam filters usually discard. Name a mailbox here and
    // it's sent over an authenticated TLS session instead.
    'mail_from'      => 'no-reply@seancheren.com',
    'mail_from_name' => 'seancheren.com',
    'smtp_host'      => '',        // e.g. smtp.nyc1.nearlyfreespeech.net
    'smtp_port'      => 587,       // 587 STARTTLS, or 465 for implicit TLS
    'smtp_user'      => '',
    'smtp_pass'      => '',

    // The clock every page runs on. The server keeps UTC; leaving this unset means
    // America/Chicago, so "today" turns over at local midnight.
    'timezone' => 'America/Chicago',
];
