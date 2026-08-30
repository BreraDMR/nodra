import { readFileSync } from "node:fs";
import { test } from "node:test";
import assert from "node:assert/strict";
import { copy, type Locale } from "../src/lib/shop.ts";
import { orderCopy } from "../src/lib/order.ts";

// D06.5: the storefront check. The copy dictionaries keep cs/de/en in lockstep,
// and the promises the storefront states in words match the numbers the API
// calculates with (points per 100 Kč, 14 days to return, no hardcoded delivery
// fee anywhere — that one comes from the quote).

const locales = Object.keys(copy) as Locale[];

function leaves(value: unknown, path: string[] = []): [string, unknown][] {
  if (value !== null && typeof value === "object") {
    return Object.entries(value).flatMap(([key, child]) =>
      leaves(child, [...path, key]),
    );
  }
  return [[path.join("."), value]];
}

function placeholderSet(text: string): string[] {
  return [...String(text).matchAll(/\{(\w+)\}/g)].map((m) => m[1]).sort();
}

test("every locale carries the same copy keys", () => {
  for (const dictionary of [copy, orderCopy] as const) {
    const base = leaves(dictionary.cs);
    for (const locale of locales) {
      const theirs = leaves(dictionary[locale]);
      assert.deepEqual(
        theirs.map(([key]) => key),
        base.map(([key]) => key),
        `${locale} keys differ from cs`,
      );
    }
  }
});

// czkNote is empty in cs on purpose: Czech shoppers see CZK prices, so there
// is nothing to explain. de/en see the EUR reference and the note tells them
// the order itself runs in CZK (D00.5; the split goes away with D06.7).
const mayBeEmpty = new Set(["orderCopy.czkNote"]);

test("no copy string is empty and the arrays keep their length per locale", () => {
  for (const dictionary of [copy, orderCopy] as const) {
    for (const locale of locales) {
      for (const [key, value] of leaves(dictionary[locale])) {
        if (
          value === "" &&
          mayBeEmpty.has(`${dictionary === copy ? "copy" : "orderCopy"}.${key}`)
        ) {
          continue;
        }
        if (Array.isArray(value)) {
          assert.ok(value.length > 0, `${locale} ${key} is an empty array`);
        } else {
          assert.equal(typeof value, "string", `${locale} ${key} type`);
          assert.ok(
            (value as string).trim() !== "",
            `${locale} ${key} is empty`,
          );
        }
      }
    }
  }
});

test("placeholders survive the translation", () => {
  for (const dictionary of [copy, orderCopy] as const) {
    const base = leaves(dictionary.cs).filter(([, v]) =>
      /\{\w+\}/.test(String(v)),
    );
    for (const [key] of base) {
      const wanted = placeholderSet(
        String(leaves(dictionary.cs).find(([k]) => k === key)?.[1]),
      );
      for (const locale of locales) {
        const theirs = leaves(dictionary[locale]).find(([k]) => k === key);
        assert.deepEqual(
          placeholderSet(String(theirs?.[1])),
          wanted,
          `${locale} ${key} placeholders`,
        );
      }
    }
  }
});

// The numbers the words promise; the API side is pinned by the phpunit tests
// (QuoteApiTest: fee 149, free from 50000; Loyalty: 1 point per 10000 minor).
const POINTS_PER_KCZ = 100;
const RETURN_DAYS = 14;

test("the points promise names the same hundred the API earns by", () => {
  const re = new RegExp(`\\D${POINTS_PER_KCZ}\\s*(Kč|Kč\\.|kč)`);
  for (const locale of locales) {
    assert.match(copy[locale].pointsRule, re, `${locale} pointsRule`);
    assert.match(copy[locale].pointsRule, /1\b/, `${locale} pointsRule point`);
  }
});

test("the returns promise matches the claim window", () => {
  const re = new RegExp(`\\b${RETURN_DAYS}\\b`);
  for (const locale of locales) {
    assert.match(copy[locale].returnsNote, re, `${locale} returnsNote`);
  }
});

// The Prague delivery terms are an owner decision since 28.09 (149 Kč, free from
// 500 Kč) and the quote still calculates them (phpunit QuoteApiTest pins the same
// numbers). The home page states them in words, so every locale must name the
// same numbers — a silent drift away from the API would promise something else.
const PRAGUE_FEE = 149;
const FREE_FROM = 500;

test("the home delivery promise names the agreed Prague tariffs", () => {
  for (const locale of locales) {
    const text = copy[locale].home.deliveryText;
    assert.match(text, new RegExp(`\\D${PRAGUE_FEE}`), `${locale} fee`);
    assert.match(text, new RegExp(`\\D${FREE_FROM}`), `${locale} threshold`);
  }
});

test("no delivery fee or threshold leaks beyond the home delivery copy", () => {
  // outside the agreed home block the quote still calculates these; a stray
  // number here would quietly drift away from the API
  const forbidden = /149\s*Kč|500\s*Kč|free from 500/i;
  for (const file of ["src/lib/order.ts", "src/lib/cart.ts"]) {
    const text = readFileSync(new URL(`../${file}`, import.meta.url), "utf8");
    assert.doesNotMatch(
      text,
      forbidden,
      `${file} hardcodes a delivery promise`,
    );
  }
  for (const locale of locales) {
    const text = [
      copy[locale].demo,
      copy[locale].returnsNote,
      copy[locale].consultAsk,
      copy[locale].consultAskPending,
    ].join("\n");
    assert.doesNotMatch(text, forbidden, `${locale} copy hardcodes a tariff`);
  }
});

// D00.5 / D06.7: one currency everywhere. The API ships CZK prices on every
// language (phpunit ProductCurrencyTest) and the order charges the same koruna
// amount; the euro next to the price is a label the shop never converts with.
test("the basket and the order run in koruna, euro is a label only", () => {
  for (const file of [
    "src/lib/shop.ts",
    "src/lib/order.ts",
    "src/lib/cart.ts",
    "src/components/BuyBox.tsx",
    "src/app/[locale]/basket/page.tsx",
  ]) {
    const text = readFileSync(new URL(`../${file}`, import.meta.url), "utf8");
    assert.doesNotMatch(
      text,
      /price_eur|from_eur/,
      `${file} reads a euro column for the calculation`,
    );
    assert.doesNotMatch(text, /"EUR"/, `${file} hardcodes the euro currency`);
    assert.doesNotMatch(
      text,
      /rate\s*[:=]|exchange\s*rate/i,
      `${file} converts currencies on its own`,
    );
  }
  // cs stays silent (a Czech sees koruna anyway); de/en explain the CZK order
  assert.equal(orderCopy.cs.czkNote, "");
  for (const locale of ["de", "en"] as const) {
    assert.match(orderCopy[locale].czkNote, /CZK/);
  }
});

// P02: the portfolio demo names its boundary in every language, the banner is
// really wired into the locale layout, and the admin login never displays
// credentials — a public visitor must not get a key to the working admin.
test("the demo mode labels the shop and the admin login leaks no credentials", () => {
  for (const locale of locales) {
    assert.match(copy[locale].demoBanner, /[Dd]emo|portfol/);
    assert.ok(
      copy[locale].demoOrder.trim().length > 10,
      `${locale} order note`,
    );
  }
  const layout = readFileSync(
    new URL("../src/app/[locale]/layout.tsx", import.meta.url),
    "utf8",
  );
  assert.match(layout, /DemoBanner/, "the demo banner is not in the layout");
  for (const file of [
    "src/app/[locale]/checkout/page.tsx",
    "src/app/[locale]/order/page.tsx",
  ]) {
    const text = readFileSync(new URL(`../${file}`, import.meta.url), "utf8");
    assert.match(text, /isDemoMode/, `${file} ignores the demo flag`);
    assert.match(text, /demoOrder/, `${file} has no demo order note`);
  }
  const admin = readFileSync(
    new URL("../src/app/admin/page.tsx", import.meta.url),
    "utf8",
  );
  assert.doesNotMatch(
    admin,
    /NodraDemo2026/,
    "the demo admin password in the login screen",
  );
  assert.doesNotMatch(
    admin,
    /account: \S+@\S+ \/ \S+/,
    "a credential hint on the admin login screen",
  );
});

// P03: until real photos exist (D07.2) the demo names the shared category
// scene an illustration — on catalog tiles and on the product page.
test("the demo labels shared images as illustrations", () => {
  for (const locale of locales) {
    assert.ok(
      copy[locale].illustrationNote.trim().length > 10,
      `${locale} illustration note`,
    );
    assert.ok(
      copy[locale].illustrationChip.trim().length > 3,
      `${locale} illustration chip`,
    );
  }
  const card = readFileSync(
    new URL("../src/components/ProductCard.tsx", import.meta.url),
    "utf8",
  );
  assert.match(card, /isDemoMode/, "the catalog tile ignores the demo flag");
  assert.match(
    card,
    /illustrationChip/,
    "the catalog tile has no illustration chip",
  );
  const detail = readFileSync(
    new URL("../src/app/[locale]/shop/[slug]/page.tsx", import.meta.url),
    "utf8",
  );
  assert.match(detail, /isDemoMode/, "the product page ignores the demo flag");
  assert.match(
    detail,
    /illustrationNote/,
    "the product page has no illustration note",
  );
});
