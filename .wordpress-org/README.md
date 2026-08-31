# Listing assets

Synced to WordPress.org SVN `/assets` on every tagged deploy. Currently empty --
the live listing has no icon, banner or screenshots.

Required:

| File | Size | Notes |
|---|---|---|
| `icon-128x128.png` | 128x128 | |
| `icon-256x256.png` | 256x256 | Retina |
| `banner-772x250.png` | 772x250 | |
| `banner-1544x500.png` | 1544x500 | Retina |
| `screenshot-1.png` ... | 1280x800 | Order must match readme.txt's Screenshots section |

`icon.svg` is also accepted and preferred where it renders cleanly at 128px.

This file is excluded from the plugin zip by `.distignore`, and the directory
itself never ships -- it goes to `/assets`, which is a sibling of `/trunk`.
