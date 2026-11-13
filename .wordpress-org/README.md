# Listing assets

Synced to WordPress.org SVN `/assets` on every tagged deploy.

Icons and banners are PRESENT. Screenshots are still pending -- see the Screenshots
section of readme.txt for the order they must match.

The banners (since 2.1.1) are supplied artwork: `banner-1544x500.png` is the file
from the brand folder as delivered, and `banner-772x250.png` is that file scaled
to half. Replace both together.

Both icons are GENERATED, not drawn: `icon.svg` and the icons
compose the logo's own vector paths, pulled out of `components/ui/logo.tsx` in the
main repo by regex. Never retype path data -- breaking it on whitespace silently
corrupts the shape. Regenerate rather than edit.

The icon sits on ink `#1F1E1B` deliberately. These render at 128px in a grid of
mostly pastel plugin tiles on a white page, and the brand's own cream would have
almost no separation there; ink gives contrast and lets the mint read. White on
mint inside the bubble is the logo's own sanctioned usage.

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
