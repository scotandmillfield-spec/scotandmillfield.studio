<?php
// Copy to config.php ON THE SERVER ONLY (never commit config.php) and fill in the
// values from the Strato customer area -> Datenbanken.
return [
  'db_host'       => 'rdbms.strato.de',      // host shown by Strato
  'db_name'       => 'DB0000000',
  'db_user'       => 'U0000000',
  'db_pass'       => 'change-me',
  // secret for running database migrations via api.php?action=migrate
  // also used to create a user / reset a password (api.php?action=user_set)
  'migrate_token' => 'replace-with-a-long-random-string',
  // optional: logout after this many seconds without activity (default 7200 = 2 hours)
  // 'session_idle' => 7200,
  // ONLY for local tests without HTTPS – never set this on the server
  // 'allow_http' => true,
];
