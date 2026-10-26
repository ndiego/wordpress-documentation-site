---
title: Install WordPress
description: Install WordPress with your host's one-click installer or by hand.
type: guide
order: 20
---

# Install WordPress

## Choose a host

Most hosts install WordPress for you.

> [!TIP]
> A local install with Studio is the fastest way to try WordPress.

## Install by hand

Download WordPress, upload it, then run the installer:

```bash
wp core download --locale=en_US
wp core install --url=example.org --title="My site" --admin_user=admin
```

> [!WARNING]
> Keep a backup of `wp-config.php`: it holds your database password.
