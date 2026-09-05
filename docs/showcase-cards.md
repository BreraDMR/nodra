# Showcase set of product cards

Put together for the portfolio version. The principle: only cards with real data from the
seed and from the earlier data checks; nothing is made up. Existing cards are not deleted or
changed. This set only records what to show in the demo scenario and in screenshots.

## The set: 15 cards, 3 categories

**Lights (7)**: facet "Umístění"; within the front lights they are told apart by the power
in the name:

- sigma-buster-800-front: a pilot card; MPN 19800 and EAN 4016224198009 are confirmed
- sigma-buster-300-front: has the position attribute, price/name with the power
- cateye-ampp-400s-front, cateye-ampp800-front: MPN/EAN not confirmed, left empty
- lezyne-hecto-drive-500xl-front: MPN/EAN empty
- knog-blinder-pro-900-front: MPN/EAN empty
- sigma-buster-rl150-brake-rear: a rear light, for contrast with the fronts

**Bags (5)**: facets "Uchycení" and "Objem"; told apart by the volume in the name:

- topeak-backloader-6 / -10 / -15: the same mount (under the saddle), three volumes
- topeak-midloader-3: frame mount
- topeak-compact-handlebar-2: handlebar mount

**Components (3)**: facets "Velikost kola", "Šířka", "Počet rychlostí":

- continental-grand-prix-5000-25-622: a pilot card; MPN/EAN confirmed against a supplier listing
- schwalbe-marathon-plus-35-622: the EAN for 35-622 could not be confirmed, so it is left
  empty (honestly)
- shimano-slx-cs-m7100-12sp: a pilot card; MPN confirmed against a supplier listing

## Ready-made browsing paths (for the demo scenario)

Checked live in a browser on a copy of the database (facet counts are the actual ones):

1. **Lights:** `/cs/shop?category=lights` → facet "Umístění: Přední (8)" → 8 front lights,
   pick by the power in the name; the product page shows the lead time and the exact variant
   (SKU). Rear lights are a separate facet, "Zadní (8)".
2. **Bags:** `/cs/shop?category=bags` → facet "Uchycení: Pod sedlo (6)" → 6 bags, with
   BackLoader 6/10/15 side by side, picked by volume (facet "Objem").
3. **Components:** `/cs/shop?category=tyres` → facet "Velikost kola: 622 (2)" → two tyres,
   25 and 35 mm (plus facets "Šířka", "Tubeless ready", "Patka"); the SLX 12sp cassette is
   in the neighboring category `cassettes` with the facet "Počet rychlostí".

## Honest limits of the set

- **Photos** are generic category illustrations (one scene per type), not product photos. In
  demo mode this is labeled on the tile ("ilustrace" / "Symbolfoto" / "illustrative") and
  under the photo on the product page. Replacing them with real photos is waiting on the
  owner's decision.
- **Availability and lead times** are the seed's demo offers (supplier `demo`), and some
  cards honestly say "Dostupnost a termín ověříme". The demo boundary is called out by the
  demo badge; the supplier is not visible to the customer.
- **MPN/EAN** are filled in only where confirmed (4 models), everything else is empty.
- **Prices** are demo values from a price snapshot; don't change them by hand.
- **Sizes/colors**: each card has a single variant. For lights, bags and components that
  makes sense; clothing is left out of the set (size variants would be expected there, and
  there is no size chart data).

## How to show it

Build the storefront with `NEXT_PUBLIC_DEMO_MODE=1`, and run it with `API_INTERNAL_URL`
pointing at a demo copy of the database (`scripts/restore.sh` from a fresh dump). See
`docs/demo-deployment.md` for the setup.
