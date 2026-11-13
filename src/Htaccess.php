<?php
declare(strict_types=1);

namespace Ramonak;

/**
 * The .htaccess of the document root: built pages are sent by the web server (LiteSpeed or Apache) without PHP; the rest
 * goes to index.php. Written by bin/ramonak htaccess from the configuration, as noindex depends on it.
 */
final class Htaccess
{
    public static function render(bool $experimental, string $cacheDir = '_cache'): string
    {
        $robots = $experimental ? "\n<IfModule mod_headers.c>\n  Header always set X-Robots-Tag \"noindex, nofollow\"\n</IfModule>\n"
            : "\n<IfModule mod_headers.c>\n  Header always set Strict-Transport-Security \"max-age=31536000\"\n</IfModule>\n";
        return <<<HT
# Written by bin/ramonak htaccess; change the configuration and write it again instead of editing it.
Options -Indexes -MultiViews
DirectoryIndex index.php
AddDefaultCharset utf-8
{$robots}
<IfModule mod_rewrite.c>
  RewriteEngine On

  # one scheme: https
  RewriteCond %{HTTPS} !=on
  RewriteCond %{HTTP:X-Forwarded-Proto} !=https
  RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]

  # the built pages are reached only through their addresses
  RewriteCond %{THE_REQUEST} \\s/{$cacheDir}/ [NC]
  RewriteRule ^ - [R=404,L]

  # a built page, for GET and HEAD without a query
  RewriteCond %{REQUEST_METHOD} ^(GET|HEAD)$
  RewriteCond %{QUERY_STRING} ^$
  RewriteCond %{REQUEST_URI} ^/$
  RewriteCond %{DOCUMENT_ROOT}/{$cacheDir}/%{HTTP_HOST}/index.html -f
  RewriteRule ^ {$cacheDir}/%{HTTP_HOST}/index.html [L]

  RewriteCond %{REQUEST_METHOD} ^(GET|HEAD)$
  RewriteCond %{QUERY_STRING} ^$
  RewriteCond %{REQUEST_URI} ^(/.*[^/])$
  RewriteCond %{DOCUMENT_ROOT}/{$cacheDir}/%{HTTP_HOST}%1/index.html -f
  RewriteRule ^ {$cacheDir}/%{HTTP_HOST}%1/index.html [L]

  # everything else: a file that exists, or the front controller
  RewriteCond %{REQUEST_FILENAME} !-f
  RewriteRule ^ index.php [L]
</IfModule>

<IfModule mod_headers.c>
  Header always set X-Content-Type-Options "nosniff"
  Header always set X-Frame-Options "SAMEORIGIN"
  Header always set Referrer-Policy "strict-origin-when-cross-origin"
</IfModule>

<IfModule mod_deflate.c>
  AddOutputFilterByType DEFLATE text/html text/css text/plain text/xml application/xml application/javascript application/json image/svg+xml
</IfModule>

<FilesMatch "\\.(css|js|webp|avif|png|jpe?g|svg|woff2)$">
  <IfModule mod_headers.c>
    Header set Cache-Control "public, max-age=31536000, immutable"
  </IfModule>
</FilesMatch>

<FilesMatch "\\.html$">
  <IfModule mod_headers.c>
    Header set Cache-Control "public, max-age=0, must-revalidate"
  </IfModule>
</FilesMatch>

HT;
    }
}
