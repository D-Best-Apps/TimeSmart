<?php
// Refuse to run over HTTP.
//
// Everything under the web root is served by nginx's `location ~ \.php$`,
// including app/scripts/. The cron jobs invoke these scripts as
// `docker exec <container> php /var/www/html/scripts/<name>.php`, so they only
// ever need the CLI SAPI — but without this guard anyone who can reach the
// site could fire off a mass clock-out by requesting the URL.
//
// nginx also denies /scripts/ outright (deploy/docker/nginx.conf); this is the
// second layer, so the scripts stay safe even if that config is lost or the
// app is deployed behind a different web server.
//
// Include this FIRST in any script meant for cron/CLI use.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
