# Deploying to cPanel

This puts Unsaid on a subdomain such as `unsaid.yourdomain.com`. It
doesn't touch anything else on the account, such as your existing sites.

**Requirements:** PHP 8.1 or later with the `pdo_mysql` extension, and MySQL
5.7+ or MariaDB 10.3+. Most cPanel hosts have both.

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

| What you see | Likely cause |
|---|---|
| "Missing config.php" | Step 4: the file must be in `unsaid/`, not in `public/`. |
| "Something went wrong" | Usually wrong database details. Check `unsaid/public/error_log` or cPanel's **Errors** page for the exact message. |
| The page loads but says "Loading…" forever, or `/api/...` gives a 404 | The `.htaccess` file wasn't extracted or `mod_rewrite` is off. Turn on **Show Hidden Files** in File Manager's settings and check `public/.htaccess` exists. |
| A page full of PHP errors about syntax | PHP is older than 8.1. Redo step 5. |
| The browser warns that the site isn't secure | AutoSSL hasn't finished. Wait, or rerun step 6. |

## Updating

Upload and extract the new zip over the old folder. Your `config.php` and
database aren't in the zip, so they're kept.

## Backups

Everything players do is in the database. To back it up, use **phpMyAdmin →
Export**, or include the database in cPanel's **Backup**.
