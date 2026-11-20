# PHP Task Board

A lightweight multi-user task and message board for PHP 8.2+ and MySQL/MariaDB shared hosting.

## Included
- Session login; password hashes using PHP `password_hash()`
- Admin and regular user roles; admin can create users
- Task CRUD, multi-assignee selection, status and parent task
- Filter by assignee/status and search by title/description
- Comments, attachments, history and task relationships
- Admin can add status labels and mark completion statuses
- PDO prepared statements, output escaping, CSRF tokens and upload MIME checks

## Install on shared hosting
1. Create a MySQL database and database user in your hosting control panel.
2. Open phpMyAdmin, select the new database, and import `schema.sql`.
3. Copy `config.example.php` to `config.php` and fill in the database credentials.
4. Upload the project files to your website's document root.
5. Ensure the `uploads` directory is writable by PHP (usually permissions 755; use your host's recommended setting).
6. Visit `https://your-domain.example/?page=install` and create the first administrator.
7. Sign in, then use Administration to create other users.
8. **Delete or rename the install page access** after creating the first account. It is also protected against creating another admin once any user exists.

## Important security / hosting notes
- Use HTTPS. Do not use this for sensitive or regulated data without a security review.
- Keep `config.php` out of public source control. If your host supports it, place it outside the web root.
- `uploads/.htaccess` disables script execution on Apache. On non-Apache hosts, configure the uploads directory so PHP and other scripts cannot execute there.
- Set `upload_max_filesize` and `post_max_size` in PHP settings to at least 5M (or lower the app limit in config.php).
- The upload allowlist is intentionally conservative. Adjust it in `index.php` if you need more file types.
- The app uses Bootstrap from a CDN; it needs internet access to load the styling.
- Database relationships are one-directional as entered: for example, on task A choose "blocks" and task B to mean A blocks B.
- Task history currently records task creation, updates, comments, attachments and relationship changes. For a detailed field-by-field audit trail, extend `save_task` to compare old and new values.
- Admin user management currently supports creating users. Password reset, editing/deactivating users and status rename/delete are not yet included.
- Deleting a task cascades its comments, attachments metadata, history and links. Uploaded physical files may remain orphaned in `uploads`; periodically clean them up.

## Requirements
- PHP 8.2+ recommended
- PDO MySQL extension
- Fileinfo extension
- MySQL 5.7+ / MariaDB 10.3+ recommended
