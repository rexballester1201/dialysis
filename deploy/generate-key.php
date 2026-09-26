<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| APP_KEY generator
|--------------------------------------------------------------------------
|
| `php artisan key:generate` needs a shell. This does the same job from a
| browser: 32 cryptographically random bytes, base64-encoded, in the format
| Laravel expects.
|
|   1. Set SECRET below to a long random string.
|   2. Upload alongside index.php.
|   3. Visit  https://your-domain/generate-key.php?key=THE-SECRET
|   4. Paste the value into APP_KEY in .env.
|   5. DELETE THIS FILE.
|
| The key encrypts whatever Laravel is asked to encrypt. Nothing in this
| system does today: passwords are bcrypt hashes and Sanctum stores sign-in
| tokens as SHA-256 hashes, so neither depends on the key and both survive a
| change. Generate it once anyway and keep a copy -- anything added later that
| encrypts with it (an encrypted column, a cookie) would be unreadable under a
| new key.
|
*/

const SECRET = 'CHANGE-ME-BEFORE-UPLOADING';

// A length test rather than a comparison against the placeholder, so that
// setting the secret with a find-and-replace cannot brick the guard.
if (strlen(SECRET) < 20) {
    http_response_code(500);
    exit('Edit generate-key.php and set SECRET to a random string of at least 20 characters.');
}

if (($_GET['key'] ?? '') !== SECRET) {
    http_response_code(404);
    exit('Not found.');
}

$key = 'base64:'.base64_encode(random_bytes(32));

header('Content-Type: text/html; charset=utf-8');

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>APP_KEY</title>
<style>
  body { margin:0; background:#f4f6f5; color:#16221f;
         font:15px/1.6 system-ui,-apple-system,'Segoe UI',sans-serif }
  .wrap { max-width:660px; margin:8vh auto; padding:0 20px }
  h1 { font-size:1.3rem; margin:0 0 6px }
  p { color:#5c6b67 }
  code { display:block; background:#fff; border:1px solid #d7dfdc; border-radius:5px;
         padding:14px 16px; margin:18px 0; font-family:ui-monospace,monospace;
         font-size:.95rem; word-break:break-all; color:#16221f }
  .warn { background:#f8efde; color:#9a6410; padding:12px 15px; border-radius:5px; font-size:.92rem }
</style>
</head>
<body>
<div class="wrap">
  <h1>APP_KEY</h1>
  <p>Paste this into <strong>APP_KEY</strong> in your <code style="display:inline;padding:1px 5px;border:none;background:#eaefed">.env</code>, including the <em>base64:</em> prefix.</p>
  <code><?= htmlspecialchars($key, ENT_QUOTES) ?></code>
  <p class="warn">
    Generate this once and keep a copy somewhere safe. Changing it later signs
    nobody out today, but anything the system encrypts with it in future would
    become unreadable under a new key. Then delete this file from the server.
  </p>
</div>
</body>
</html>
