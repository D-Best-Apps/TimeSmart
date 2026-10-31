<?php
// Router for PHP's built-in web server (deploy/dev/start.ps1), mirroring the
// production nginx rules in deploy/docker/nginx.conf:
//   - /scripts/ is never served (CLI-only cron scripts)
//   - extensionless URLs fall back to the .php file (try_files $uri.php)
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
$root = $_SERVER['DOCUMENT_ROOT'];

if (str_starts_with($path, '/scripts/') || preg_match('#/\.ht#', $path)) {
    http_response_code(403);
    exit('Forbidden');
}
if ($path !== '/' && !file_exists($root . $path) && is_file($root . $path . '.php')) {
    $_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = $path . '.php';
    chdir(dirname($root . $path . '.php'));
    require $root . $path . '.php';
    return true;
}
return false; // let the built-in server handle files and directories as usual
