# WordPress.org listing assets

These images are NOT shipped inside the plugin zip. They live in the SVN
`/assets` directory of the wordpress.org plugin page (the standard
`10up/action-wordpress-plugin-deploy` GitHub Action copies this `.wordpress-org`
folder there automatically).

Add the following PNGs here before submitting (none exist yet - placeholders to
create):

| File | Size | Purpose |
| --- | --- | --- |
| `icon-128x128.png` | 128x128 | plugin icon (small) |
| `icon-256x256.png` | 256x256 | plugin icon (retina) |
| `banner-772x250.png` | 772x250 | header banner |
| `banner-1544x500.png` | 1544x500 | header banner (retina) |
| `screenshot-1.png` | any | the `[dumbouncer_form]` contact form |
| `screenshot-2.png` | any | Settings -> Dumbouncer screen |

Each `screenshot-N.png` is described by the matching numbered line under the
`== Screenshots ==` section of `readme.txt` (add that section when the images
exist). An optional `icon.svg` may replace the PNG icons.

Design note: the brand line is "Dumb bots bounce." Keep it plain and text-led,
consistent with the no-CAPTCHA, no-third-party positioning.
