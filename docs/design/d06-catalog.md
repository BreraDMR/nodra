# D06.2 — catalog navigation, search, filters and the mobile catalog

Working spec for the storefront catalog: native category navigation, model/MPN search, attribute filters and an understandable mobile catalog. It covers the storefront only; the admin catalog screens are D05.

## What already exists (built with D01–D04)

- `GET /api/products` searches `q` across product name (all languages), slug, brand, and variant MPN and EAN (CatalogService). The shop page and the header search both send plain GET forms, so search works before hydration.
- The shop page navigates the tree: root tabs, chips for the selected root's children (or the selected leaf's siblings), breadcrumbs are implicit in the tabs' selected state.
- `GET /api/categories/{slug}/facets` feeds the attribute filters: brand plus the category's own attributes, each value with a count. The API refuses unknown attribute keys with 422 and the page drops junk from the URL.
- Sorting (featured / price / newest), `availableOnly` (own stock or a fresh matched offer), pagination with a window.
- The layout is responsive: the grid drops to two columns ≤1000px, the header stacks its tools ≤700px, the checkout and detail pages go single-column.

The tree today is two levels deep (5 roots, `components` with 8 children), but nothing here may assume that.

## Gap 1 — the category tree in the header navigation (this task)

The header shows only the root names, so a visitor sees `components` but never its children until they land on the shop page. That is the "native navigation" gap.

**Design:**

- Each root with children gets a disclosure menu built from `<details class="header-menu"><summary>…</summary>` — no JavaScript, no hydration, works with the keyboard and before React wakes up. A root without children stays a plain link.
- The summary keeps the root's name and the plain-link behaviour is preserved by the existing link inside the panel ("All …"): clicking the root name itself opens the menu; the panel's first link goes to the root category, the rest to its children with their published-product counts. (The shop page keeps its root tabs for direct clicking.)
- The panel lists `children` in API order and shows `count` only when it is non-zero. Empty categories are hidden, as everywhere else.
- Deeper trees (none today) render the children the same way; the menu is not a full sitemap, it is one level per opening, like the shop page's tabs and chips.
- Counts come from `GET /api/categories?locale=…` that the header already fetches. When the API is down the header still renders without the menus (existing behaviour).

**Out of scope:** mega menus, category images, active-path highlighting in the header (the shop page already highlights the selected tab/chip).

## Gap 2 — the mobile catalog (this task)

Verified at a phone viewport (390×844) against the stand, with the toolbar, chips, filters, grid and pagination reachable and nothing overflowing:

- The root tabs and the subcategory chips scroll horizontally in one line instead of wrapping into a tall block; the first item starts visible.
- The filters row keeps its selects usable at 320px width (they may stack; no horizontal page scroll).
- The header search keeps its 16px input font (iOS zoom rule already in place).
- Product cards keep the two-column grid; a card's title and price must not clip.

**Out of scope:** a drawer menu, sticky toolbars, infinite scroll. The pagination links stay.

## Tests to cover

There is no JavaScript test setup in the repo (decided with the D04/D05 work: the web is covered by `npm run lint`, `format:check`, `next build` and manual/browser passes against the stand). For this task:

- API behaviour of search, facets and `availableOnly` is already covered by `tests/Catalog/` — no new API code here, so no new API tests.
- The header menu is markup + CSS only; the browser pass on the stand (desktop and 390px) is the check: the menu opens by click and keyboard, lists the children with counts, the shop page opens from its links, nothing overflows at 390px.
- `./scripts/check.sh` stays green (lint, format, build).
