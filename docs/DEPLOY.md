# Deploying to cPanel

This puts Unsaid on a subdomain such as `unsaid.yourdomain.com`. It
doesn't touch anything else on the account, such as your existing sites.

**Requirements:** PHP 8.1 or later with the `pdo_mysql` and `mbstring`
extensions, and MySQL 5.7+ or MariaDB 10.3+. Most cPanel hosts have these.

## 1. Upload the files

1. In **File Manager**, go to your home folder (the one that contains
   `public_html`, not inside it).
2. Upload `unsaid.zip` and **Extract** it. You get a folder called
   `unsaid` containing `public`, `src`, `data` and so on.

Only `unsaid/public` will be visible on the web. The code, word list and
your database password stay outside it.

## 2. Create the subdomain

1. Go to **Domains** (older cPanel: **Subdomains**) and create
   `unsaid.yourdomain.com`.
2. Set its **document root** to `unsaid/public`. cPanel usually suggests
   something like `public_html/unsaid`. Change it.

## 3. Create the database

1. Go to **MySQL Databases**.
2. Create a database, for example `unsaid`. cPanel adds your account
   name in front, giving something like `myacct_unsaid`.
3. Create a user, for example `game`, with a strong password (use the password
   generator). It becomes `myacct_game`.
4. Under **Add User To Database**, add the user to the database and tick
   **ALL PRIVILEGES**.

There's no need to import anything. The game creates its tables the first time
it runs.

## 4. Add your database details

1. In File Manager, open `unsaid`, copy `config.example.php` and name the
   copy `config.php`.
2. Edit `config.php` and fill in the full database name, user and password
   from step 3. `db_host` is almost always `localhost`.

## 5. Check the PHP version

1. Go to **MultiPHP Manager**, tick the subdomain, and choose PHP 8.1 or later.
   Some servers ignore this setting, so `public/.htaccess` also selects cPanel's
   PHP 8.1 (`ea-php81`). To use a newer PHP, change `ea-php81` there to, for
   example, `ea-php83`.
2. If your host has **Select PHP Version** (CloudLinux), check under
   **Extensions** that `pdo_mysql` is ticked.

## 6. Turn on HTTPS

Go to **SSL/TLS Status**, tick the subdomain and click **Run AutoSSL**. It can
take a few minutes. New subdomains can also take a little while to start
working while DNS updates.

## 7. Play

Visit `https://unsaid.yourdomain.com`. You should see the public world with a
prompt. Click **New private world** to make one for friends, then use **Copy
invite link** to share it.

## Troubleshooting

**Start by visiting `https://unsaid.yourdomain.com/check.php`.** It tests the
PHP version, extensions, files, `config.php` and the database connection, and
says how to fix anything that fails. Delete `public/check.php` once the game
works, because it shows details about your server.

| What you see | Likely cause |
|---|---|
| "Missing config.php" | Step 4: the file must be in `unsaid/`, not in `public/`. |
| "Something went wrong" | Usually wrong database details. Check `unsaid/public/error_log` or cPanel's **Errors** page for the exact message. |
| "The game's server isn't responding properly" | The game's PHP code isn't being reached. Usually PHP is older than 8.1 (step 5), or `public/.htaccess` is missing: turn on **Show Hidden Files** in File Manager's settings and check it exists. If `check.php` passes, visit `/api/worlds/public` and send what it shows. |
| Every PHP page (including `check.php`) shows an Apache "Internal Server Error … while trying to use an ErrorDocument" | File permissions: cPanel won't run PHP from group-writable files. In File Manager, set folders to **755** and files to **644** (select them, then **Permissions**). The zip from `scripts/build-zip.sh` already has these. |
| A page full of PHP errors about syntax | PHP is older than 8.1. Redo step 5. |
| The browser warns that the site isn't secure | AutoSSL hasn't finished. Wait, or rerun step 6. |

## Updating

Upload and extract the new zip over the old folder. Your `config.php` and
database aren't in the zip, so they're kept. If a new version needs database
changes, the game makes them itself on the first visit, keeping all worlds
and scores.

## The admin page

The admin page shows reported words and a few stats. To turn it on, add two
lines to `config.php`: a long password, and the page's address.

```php
'admin_password' => 'choose-a-long-password',
'admin_path' => 'backstage-7k2q',
```

The page is then at `https://unsaid.yourdomain.com/backstage-7k2q`. Choose your
own `admin_path` that nobody would guess (4–64 letters, numbers, `-` or `_`).
Every other address, including `/admin`, says "Not found", so people trying the
obvious learn nothing. Without an `admin_path`, the page is at `/admin`.

On the page you can:
- **Accept** a reported word: it counts straight away, with no rebuild or upload.
  A word missing from the dictionary is added to it too.
- **Dismiss** a report that shouldn't count.
- **Add a word** nobody has reported, to a meaning or just to the dictionary.
- **Undo** any decision.

Accepted words are stored in the database. To make them part of the word files
for good, add them to `data/category-overrides.txt` or `data/extra-words.txt`
when the game is next updated.

## Backups

Everything players do is in the database. To back it up, use **phpMyAdmin →
Export**, or include the database in cPanel's **Backup**.
