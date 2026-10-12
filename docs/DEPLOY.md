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

## 5. Set up sign-in emails

Players sign in with a code sent by email, once per device. The emails need a
sender address on your domain, or they're likely to land in spam.

1. In cPanel, go to **Email Accounts** and create an address such as
   `noreply@yourdomain.com`. You don't need to read its inbox.
2. Add a `mail` section to `config.php` (copy it from `config.example.php`) and
   set `'from'` to that address. Leave `'transport' => 'mail'`, which uses your
   server's own email.
3. Once the game is running, sign in with your own email to check a code arrives.
   If it lands in spam, mark it "not spam"; it improves over time.

Shared hosting usually limits how many emails an hour your account can send
(ask your host, or look in cPanel). That's plenty to start with. If sign-ups
outgrow it, switch to an email service such as Brevo (free up to 300 a day) or
Amazon SES: set `'transport' => 'smtp'` and the service's `smtp_` settings in
`config.php`. No code changes are needed.

## 6. Check the PHP version

1. Go to **MultiPHP Manager**, tick the subdomain, and choose PHP 8.1 or later.
   Some servers ignore this setting, so `public/.htaccess` also selects cPanel's
   PHP 8.1 (`ea-php81`). To use a newer PHP, change `ea-php81` there to, for
   example, `ea-php83`.
2. If your host has **Select PHP Version** (CloudLinux), check under
   **Extensions** that `pdo_mysql` is ticked.

## 7. Turn on HTTPS

Go to **SSL/TLS Status**, tick the subdomain and click **Run AutoSSL**. It can
take a few minutes. New subdomains can also take a little while to start
working while DNS updates.

## 8. Play

Visit `https://unsaid.yourdomain.com`. You should see the big world with a
prompt. Sign in with your email to play. **Join a room** puts you in a room of
up to 60 players. Click **New private world** to make one for friends, then
use **Copy invite link** to share it.

## 9. Housekeeping

A small job tidies the database once a day (old sign-in codes, expired
sessions, old misses, and the detail of worlds that finished a month ago). Set
it up once:

1. In cPanel, open **Cron Jobs**.
2. Under **Cron Email**, enter your email address if you'd like to hear about
   errors. (It only emails when something goes wrong.)
3. Under **Add New Cron Job**, choose **Once Per Day** from Common Settings,
   then change **Hour** to 4 (4am, when few people play).
4. In **Command**, paste this, changing `youraccount` to your cPanel username
   (your home directory is shown under **General Information** on cPanel's
   front page):

   ```
   /opt/cpanel/ea-php81/root/usr/bin/php /home/youraccount/unsaid/scripts/housekeeping.php > /dev/null
   ```

   This uses PHP 8.1, the same as the game. If your host has a newer version,
   `ea-php82` or `ea-php83` work too.
5. Click **Add New Cron Job**.

The admin page's **Housekeeping** section shows when it last ran. To check the
command straight away, press **Run housekeeping now** there; the next morning,
"Last ran" should show about 4am.

## Troubleshooting

**Start by visiting `https://unsaid.yourdomain.com/check.php`.** It tests the
PHP version, extensions, files, `config.php` and the database connection, and
says how to fix anything that fails. Delete `public/check.php` once the game
works, because it shows details about your server.

| What you see | Likely cause |
|---|---|
| "Missing config.php" | Step 4: the file must be in `unsaid/`, not in `public/`. |
| "Something went wrong" | Usually wrong database details. Check `unsaid/public/error_log` or cPanel's **Errors** page for the exact message. |
| "The game's server isn't responding properly" | The game's PHP code isn't being reached. Usually PHP is older than 8.1 (step 6), or `public/.htaccess` is missing: turn on **Show Hidden Files** in File Manager's settings and check it exists. If `check.php` passes, visit `/api/worlds/big` and send what it shows. |
| Every PHP page (including `check.php`) shows an Apache "Internal Server Error … while trying to use an ErrorDocument" | File permissions: cPanel won't run PHP from group-writable files. In File Manager, set folders to **755** and files to **644** (select them, then **Permissions**). The zip from `scripts/build-zip.sh` already has these. |
| Sign-in codes never arrive | Check spam first. Then check the `mail` section of `config.php` ('from' must be an address on your domain) and cPanel's **Track Delivery** page, which shows whether the email was sent or refused. |
| A page full of PHP errors about syntax | PHP is older than 8.1. Redo step 6. |
| The browser warns that the site isn't secure | AutoSSL hasn't finished. Wait, or rerun step 7. |

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
- **Set an account's plan** (Free, Host or Organiser) under **Accounts and
  plans**: find the account by name or email, pick the plan, Save. Until
  payments are added, this is how accounts get private worlds. With nothing
  searched, the list shows every account on a paid plan.
- **Recount scores**, and see when **housekeeping** last ran or run it now.

Accepted words are stored in the database. To make them part of the word files
for good, add them to the category's file in `data/categories/` (and to
`data/extra-words.txt` if they aren't in the dictionary) when the game is
next updated.

## Backups

Everything players do is in the database. To back it up, use **phpMyAdmin →
Export**, or include the database in cPanel's **Backup**.
