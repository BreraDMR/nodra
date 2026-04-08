export type Locale = "cs" | "de" | "en";
export type Money = { amount: number; currency: string };
export type Card = {
  id: string;
  slug: string;
  name: string;
  brand: string | null;
  category: string;
  categoryName: string;
  image: string;
  badge: string | null;
  fromPrice: Money;
  inStock: boolean;
  availableUnits: number;
};
export type Spec = {
  key: string;
  label: string;
  value: string;
  unit: string | null;
};
export type Variant = {
  id: string;
  sku: string;
  label: string;
  color: string | null;
  size: string | null;
  stock: number;
  price: Money;
  mpn: string | null;
  ean: string | null;
  specs: Spec[];
};
export type Product = Card & {
  short: string;
  description: string;
  details: string[];
  images: string[];
  breadcrumbs: { slug: string; name: string }[];
  specs: Spec[];
  inBox: string | null;
  variants: Variant[];
};
export type CategoryNode = {
  id: string;
  slug: string;
  name: string;
  count: number;
  children: CategoryNode[];
};
export type Facets = {
  category: string;
  brands: { value: string; count: number }[];
  attributes: {
    key: string;
    label: string;
    type: "text" | "number" | "choice";
    unit: string | null;
    values: { value: string; label: string; count: number }[];
  }[];
};
export type CartItem = {
  variantId: string;
  slug: string;
  name: string;
  label: string;
  image: string;
  price: Money;
  quantity: number;
};
export const locales: Locale[] = ["cs", "de", "en"];
export function isLocale(value: string): value is Locale {
  return locales.includes(value as Locale);
}
export const copy = {
  cs: {
    shop: "Obchod",
    story: "Náš příběh",
    storyTitle: ["Každý den", "je cesta."],
    bag: "Košík",
    account: "Můj účet",
    points: "Věrnostní body",
    pointsRule:
      "Za každých 100 Kč hodnoty zboží získáte 1 bod po dokončení objednávky. Body zatím nelze utratit.",
    googleSignIn: "Přihlásit se přes Google",
    demoSignIn: "Vyzkoušet demo účet",
    signOut: "Odhlásit se",
    noPoints: "Body se zobrazí po dokončení objednávky.",
    pointsHistory: "Historie bodů",
    previous: "Předchozí",
    next: "Další",
    googleError:
      "Přihlášení přes Google zatím není dostupné. Zkontrolujte OAuth nastavení nebo použijte demo účet.",
    demoError: "Přihlášení k demo účtu se nepodařilo.",
    heroEyebrow: "VYBAVENÍ PRO KAŽDOU CESTU",
    heroTitle: "Město končí. Jízda pokračuje.",
    heroText: "Promyšlené vybavení pro cestu přes město i daleko za něj.",
    explore: "Prohlédnout kolekci",
    featured: "Na cestu",
    featuredText: "Věci, které s vámi udrží krok.",
    all: "Vše",
    search: "Hledat produkty",
    sort: "Řadit",
    recommended: "Doporučené",
    low: "Cena: od nejnižší",
    high: "Cena: od nejvyšší",
    newest: "Nejnovější",
    add: "Přidat do košíku",
    out: "Vyprodáno",
    available: "Lze objednat",
    availableOnly: "Pouze dostupné produkty",
    back: "Zpět do obchodu",
    checkout: "Pokračovat k objednávce",
    empty: "Košík je zatím prázdný.",
    subtotal: "Mezisoučet",
    shipping: "Doprava",
    total: "Celkem",
    name: "Jméno a příjmení",
    email: "E-mail",
    address: "Ulice a číslo",
    postal: "PSČ",
    country: "Země",
    district: "Kraj / okres",
    czechOnly: "Doručení pouze v České republice",
    place: "Dokončit ukázkovou objednávku",
    demo: "Ukázkový obchod. Žádná platba ani zásilka nebude zpracována.",
    thankYou: "Děkujeme. Objednávka je připravena.",
    order: "Číslo objednávky",
    noResults: "Nic jsme nenašli. Zkuste jiný výraz.",
    quantity: "Množství",
    remove: "Odebrat",
    details: "Detaily",
    contact: "Kontaktní údaje",
    checkoutLabel: "UKÁZKOVÁ OBJEDNÁVKA",
    summaryLabel: "SOUHRN OBJEDNÁVKY",
    confirmationLabel: "POTVRZENÍ OBJEDNÁVKY",
    notesLabel: "O PRODUKTU",
    demoFooter: "Ukázkový obchod · Bez skutečných objednávek a plateb",
    shippingPending: "vypočítá se při objednávce",
    filters: "Filtry",
    brand: "Značka",
    anyBrand: "Všechny značky",
    anyValue: "Nezáleží",
    applyFilters: "Filtrovat",
    resetFilters: "Zrušit filtry",
    specs: "Technické parametry",
    inBox: "Obsah balení",
    mpn: "Kód výrobce",
    ean: "EAN",
    breadcrumb: "Drobečková navigace",
    pagination: "Stránkování",
    subcategories: "Podkategorie",
    promise: "Dobré věci jsou na dlouhé cesty.",
    promiseText:
      "Méně kompromisů, více kilometrů. Vybíráme praktickou výbavu pro každodenní jízdu.",
  },
  de: {
    shop: "Shop",
    story: "Unsere Geschichte",
    storyTitle: ["Jeder Tag", "ist eine Reise."],
    bag: "Warenkorb",
    account: "Mein Konto",
    points: "Treuepunkte",
    pointsRule:
      "Für Waren im Wert von je 4 € erhalten Sie nach Abschluss der Bestellung 1 Punkt. Punkte können noch nicht eingelöst werden.",
    googleSignIn: "Mit Google anmelden",
    demoSignIn: "Demo-Konto ausprobieren",
    signOut: "Abmelden",
    noPoints: "Punkte erscheinen nach Abschluss einer Bestellung.",
    pointsHistory: "Punkteverlauf",
    previous: "Zurück",
    next: "Weiter",
    googleError:
      "Die Google-Anmeldung ist noch nicht verfügbar. Prüfe die OAuth-Einstellungen oder verwende das Demo-Konto.",
    demoError: "Die Anmeldung beim Demo-Konto ist fehlgeschlagen.",
    heroEyebrow: "AUSRÜSTUNG FÜR JEDEN WEG",
    heroTitle: "Die Stadt endet. Die Fahrt geht weiter.",
    heroText:
      "Durchdachte Ausrüstung für den Weg durch die Stadt und weit darüber hinaus.",
    explore: "Kollektion entdecken",
    featured: "Für unterwegs",
    featuredText: "Ausrüstung, die mit dir Schritt hält.",
    all: "Alle",
    search: "Produkte suchen",
    sort: "Sortieren",
    recommended: "Empfohlen",
    low: "Preis: aufsteigend",
    high: "Preis: absteigend",
    newest: "Neueste",
    add: "In den Warenkorb",
    out: "Ausverkauft",
    available: "Bestellbar",
    availableOnly: "Nur verfügbare Artikel",
    back: "Zurück zum Shop",
    checkout: "Zur Kasse",
    empty: "Dein Warenkorb ist noch leer.",
    subtotal: "Zwischensumme",
    shipping: "Versand",
    total: "Gesamt",
    name: "Vollständiger Name",
    email: "E-Mail",
    address: "Straße und Hausnummer",
    postal: "Postleitzahl",
    country: "Land",
    district: "Region / Bezirk",
    czechOnly: "Lieferung nur innerhalb Tschechiens",
    place: "Demo-Bestellung abschließen",
    demo: "Demo-Shop. Es erfolgt weder eine Zahlung noch ein Versand.",
    thankYou: "Danke. Deine Bestellung ist eingegangen.",
    order: "Bestellnummer",
    noResults: "Keine Ergebnisse. Versuche einen anderen Begriff.",
    quantity: "Menge",
    remove: "Entfernen",
    details: "Details",
    contact: "Kontakt & Lieferung",
    checkoutLabel: "DEMO-BESTELLUNG",
    summaryLabel: "BESTELLÜBERSICHT",
    confirmationLabel: "BESTELLBESTÄTIGUNG",
    notesLabel: "PRODUKTDETAILS",
    demoFooter: "Demo-Shop · Keine echten Bestellungen oder Zahlungen",
    shippingPending: "wird bei Bestellung berechnet",
    filters: "Filter",
    brand: "Marke",
    anyBrand: "Alle Marken",
    anyValue: "Beliebig",
    applyFilters: "Filtern",
    resetFilters: "Filter zurücksetzen",
    specs: "Technische Daten",
    inBox: "Lieferumfang",
    mpn: "Herstellernummer",
    ean: "EAN",
    breadcrumb: "Brotkrümelnavigation",
    pagination: "Seitennavigation",
    subcategories: "Unterkategorien",
    promise: "Gute Dinge bleiben lange unterwegs.",
    promiseText:
      "Weniger Kompromisse, mehr Kilometer. Wir wählen praktische Ausrüstung für tägliche Fahrten aus.",
  },
  en: {
    shop: "Shop",
    story: "Our story",
    storyTitle: ["Everyday", "is a journey."],
    bag: "Basket",
    account: "My account",
    points: "Loyalty points",
    pointsRule:
      "Earn 1 point per 4 € of products after an order is completed. Points cannot yet be redeemed.",
    googleSignIn: "Continue with Google",
    demoSignIn: "Try the demo account",
    signOut: "Sign out",
    noPoints: "Points appear after an order is completed.",
    pointsHistory: "Points history",
    previous: "Previous",
    next: "Next",
    googleError:
      "Google sign-in is not available yet. Check the OAuth setup or use the demo account.",
    demoError: "Demo sign-in failed.",
    heroEyebrow: "GEAR FOR EVERY WAY THROUGH",
    heroTitle: "The city ends. The ride goes on.",
    heroText:
      "Considered gear for the route across town and the miles beyond it.",
    explore: "Explore the collection",
    featured: "Made for the miles",
    featuredText: "The pieces that keep pace with you.",
    all: "All",
    search: "Search products",
    sort: "Sort by",
    recommended: "Recommended",
    low: "Price: low to high",
    high: "Price: high to low",
    newest: "Newest",
    add: "Add to basket",
    out: "Sold out",
    available: "Available to order",
    availableOnly: "Available products only",
    back: "Back to shop",
    checkout: "Continue to checkout",
    empty: "Your basket is empty for now.",
    subtotal: "Subtotal",
    shipping: "Shipping",
    total: "Total",
    name: "Full name",
    email: "Email",
    address: "Street address",
    postal: "Postal code",
    country: "Country",
    district: "Region / district",
    czechOnly: "Delivery within Czechia only",
    place: "Place demo order",
    demo: "This is a demo shop. No payment or shipment will be made.",
    thankYou: "Thanks. Your order is in.",
    order: "Order reference",
    noResults: "Nothing matched. Try another search.",
    quantity: "Quantity",
    remove: "Remove",
    details: "Details",
    contact: "Contact & delivery",
    checkoutLabel: "DEMO CHECKOUT",
    summaryLabel: "ORDER SUMMARY",
    confirmationLabel: "ORDER CONFIRMATION",
    notesLabel: "PRODUCT NOTES",
    demoFooter: "Fictional portfolio store · No real orders or payments",
    shippingPending: "calculated at checkout",
    filters: "Filters",
    brand: "Brand",
    anyBrand: "All brands",
    anyValue: "Any",
    applyFilters: "Apply",
    resetFilters: "Reset filters",
    specs: "Specifications",
    inBox: "In the box",
    mpn: "MPN",
    ean: "EAN",
    breadcrumb: "Breadcrumb",
    pagination: "Pagination",
    subcategories: "Subcategories",
    promise: "Good things go the long way.",
    promiseText:
      "Fewer compromises, more kilometers. We select practical gear for everyday riding.",
  },
};
export function badgeName(locale: Locale, badge: string): string {
  const labels: Record<Locale, Record<string, string>> = {
    cs: { New: "Novinka", Limited: "Limitovaná", Bestseller: "Bestseller" },
    de: { New: "Neu", Limited: "Limitiert", Bestseller: "Bestseller" },
    en: { New: "New", Limited: "Limited", Bestseller: "Bestseller" },
  };
  return labels[locale][badge] || badge;
}
export function money(value: Money, locale: Locale): string {
  return new Intl.NumberFormat(
    locale === "cs" ? "cs-CZ" : locale === "de" ? "de-DE" : "en-GB",
    { style: "currency", currency: value.currency },
  ).format(value.amount / 100);
}
export async function api<T>(path: string): Promise<T> {
  const res = await fetch(
    `${process.env.API_INTERNAL_URL || "http://127.0.0.1:8000"}${path}`,
    { next: { revalidate: 15 } },
  );
  if (!res.ok) throw new Error(`API ${res.status}: ${path}`);
  return res.json() as Promise<T>;
}
