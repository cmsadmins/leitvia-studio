# Dark pages, band accent and full-height heroes

For brands with a light home page and dark inner pages, an accent that reads only on dark grounds, or a hero that fills the first screen.

## Dark pages

Give a page the template `page-dark` (like `page`: breadcrumbs, title, content) or `page-landing-dark` (like `page-landing`: no title, the hero holds the h1), with `template` in `leitvia/compose-page`, `leitvia/update-content` or `leitvia/insert-section`. The whole page turns dark, header, breadcrumbs, rules and footer included: the ground is `band` (else `ink`), text `surface`, accent text `band-accent`, buttons `band-button` with `on-band-button`, the site logo white (as the block style `on-dark`, so it needs a transparent background). Keep the sections on their usual grounds (`surface`, `surface-alt`); do not wrap them in `is-style-inverse`, which turns light on a dark page. With a dark palette the two templates look like `page` and `page-landing`. The composition check treats them like their light twins.

Links on dark pages: running text keeps accent links with underline; a heading that is one link (a title in a row of services, a cross link) reads in the text color without underline and shows the accent with the underline on hover and focus, as on Inverse bands; a link in the paragraph style `highlight` keeps the text color with its accent underline.

## Accent on dark grounds

When the brand color fails 4.5:1 on `surface` (a bright green on off-white), keep `accent` dark for light grounds and add the optional role `band-accent` to the token set ([button and band colors](tokens.md#button-and-band-colors)). Write `textColor` `accent` on numbers, marks and eyebrows as usual: on Inverse bands, `ink` groups and dark pages they show `band-accent`, on light grounds `accent`. `band-accent` needs 4.5:1 on `band` and on `ink`; never write it as a block color.

## Full-height hero

`className` `is-style-full-height` on the outer group of a hero (`hero-text`, `hero-centered`) makes it fill the first screen below the header, content centred from top to bottom, at least 24rem. Combine it with other styles of the group, space separated (`is-style-inverse is-style-full-height`). Only for the first section; covers have their own minimum height.
