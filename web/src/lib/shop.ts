export type Locale = "cs" | "de" | "en";
export type Money = { amount: number; currency: string };
// Worked out from supplier offers on the server. Lead time already includes handling
// and is null unless the status is orderable.
export type Availability = {
  status: "orderable" | "check_needed" | "unavailable";
  leadTimeMinDays: number | null;
  leadTimeMaxDays: number | null;
};
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
  availability: Availability;
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
  availability: Availability;
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
    metaTitle: "NODRA — komponenty a doplňky na kolo",
    metaDescription:
      "Komponenty a doplňky na kolo v Praze. Poradíme s výběrem, doručíme po Praze nebo si objednávku vyzvednete na Andělu.",
    announcement: "NODRA / VYBAVENÍ NA KAŽDODENNÍ JÍZDU",
    location: "PRAHA · ČESKO",
    homeLabel: "NODRA – úvodní stránka",
    mainNav: "Hlavní navigace",
    language: "Jazyk",
    searchSubmit: "Hledat",
    categories: "Kategorie",
    heroImageAlt: "Cyklista za svítání v Praze",
    heroTagline: "JEZDĚTE DÁL NEŽ OBVYKLE",
    introLabel: "NODRA / KAŽDODENNÍ JÍZDA",
    featuredLabel: "VYBRÁNO NA CESTU",
    storyLabel: "NODRA / NÁŠ PŘÍSTUP",
    storyImageAlt: "Brašna do rámu na kole",
    marquee: ["JEĎTE S CHUTÍ", "DOJEĎTE DÁL", "ZVOLTE DELŠÍ CESTU"],
    footerTagline: "DOBRÁ VÝBAVA. VOLNÁ CESTA.",
    collectionLabel: "NODRA / KATALOG",
    selectionLabel: "NODRA / VÁŠ VÝBĚR",
    accountLabel: "NODRA / ZÁKAZNICKÝ ÚČET",
    orderNotFound: "Objednávku se nepodařilo najít.",
    orderFailed: "Objednávku se nepodařilo odeslat.",
    contactUs: "Napište nám",
    sellerLabel: "Prodávající",
    companyId: "IČO",
    checkNeeded: "Dostupnost a termín ověříme",
    unavailable: "Momentálně nedostupné",
    cannotOrder:
      "{item} teď bohužel nelze objednat. Odeberte ho prosím z košíku a zkuste to znovu.",
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
    metaTitle: "NODRA — Fahrradkomponenten und Zubehör",
    metaDescription:
      "Fahrradkomponenten und Zubehör in Prag. Wir beraten bei der Auswahl, liefern innerhalb Prags oder Sie holen die Bestellung am Anděl ab.",
    announcement: "NODRA / AUSRÜSTUNG FÜR JEDEN TAG",
    location: "PRAG · TSCHECHIEN",
    homeLabel: "NODRA – Startseite",
    mainNav: "Hauptnavigation",
    language: "Sprache",
    searchSubmit: "Suchen",
    categories: "Kategorien",
    heroImageAlt: "Radfahrer in Prag bei Sonnenaufgang",
    heroTagline: "RAUS AUS DEM ALLTAG",
    introLabel: "NODRA / JEDEN TAG UNTERWEGS",
    featuredLabel: "AUSGEWÄHLT FÜR UNTERWEGS",
    storyLabel: "NODRA / UNSER ANSATZ",
    storyImageAlt: "Rahmentasche am Fahrrad",
    marquee: ["GUT FAHREN", "WEITERKOMMEN", "DEN LANGEN WEG NEHMEN"],
    footerTagline: "GUTE AUSRÜSTUNG. OFFENE WEGE.",
    collectionLabel: "NODRA / KATALOG",
    selectionLabel: "NODRA / DEINE AUSWAHL",
    accountLabel: "NODRA / KUNDENKONTO",
    orderNotFound: "Die Bestellung wurde nicht gefunden.",
    orderFailed: "Die Bestellung konnte nicht abgeschlossen werden.",
    contactUs: "Schreib uns",
    sellerLabel: "Verkäufer",
    companyId: "IČO (Firmennummer)",
    checkNeeded: "Verfügbarkeit und Liefertermin prüfen wir",
    unavailable: "Derzeit nicht verfügbar",
    cannotOrder:
      "{item} kann derzeit leider nicht bestellt werden. Bitte entferne den Artikel aus dem Warenkorb und versuche es erneut.",
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
    metaTitle: "NODRA — bike components and accessories",
    metaDescription:
      "Bike components and accessories in Prague. We help you choose, deliver across Prague, or you pick up your order at Anděl.",
    announcement: "NODRA / CURATED FOR THE EVERYDAY ESCAPE",
    location: "PRAGUE · CZECHIA",
    homeLabel: "NODRA home",
    mainNav: "Main navigation",
    language: "Language",
    searchSubmit: "Search",
    categories: "Categories",
    heroImageAlt: "Cyclist riding through Prague at dawn",
    heroTagline: "RIDE BEYOND THE ROUTINE",
    introLabel: "NODRA / EVERYDAY EXPLORATION",
    featuredLabel: "CURATED FOR THE ROAD",
    storyLabel: "NODRA / OUR APPROACH",
    storyImageAlt: "Cycling frame bag on a bicycle",
    marquee: ["RIDE WELL", "GO FURTHER", "TAKE THE LONG WAY"],
    footerTagline: "GOOD GEAR. OPEN ROADS.",
    collectionLabel: "NODRA / THE COLLECTION",
    selectionLabel: "NODRA / YOUR SELECTION",
    accountLabel: "NODRA / RIDER CLUB",
    orderNotFound: "Order not found",
    orderFailed: "Order could not be placed",
    contactUs: "Message us",
    sellerLabel: "Seller",
    companyId: "Company ID (IČO)",
    checkNeeded: "We'll confirm availability and delivery date",
    unavailable: "Currently unavailable",
    cannotOrder:
      "{item} can't be ordered at the moment. Please remove it from your basket and try again.",
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
// "Doručení za 3–5 dní"; one number when min and max match
export function leadTime(locale: Locale, min: number, max: number): string {
  const days = min === max ? String(max) : `${min}–${max}`;
  if (locale === "cs") {
    const word = max === 1 ? "den" : max >= 2 && max <= 4 ? "dny" : "dní";
    return `Doručení za ${days} ${word}`;
  }
  if (locale === "de")
    return `Lieferung in ${days} ${max === 1 ? "Tag" : "Tagen"}`;
  return `Delivery in ${days} ${max === 1 ? "day" : "days"}`;
}
// A page cached before the API started sending availability (up to 15 s after a
// deploy) has none; show it as "we'll check" instead of failing the whole page
const unknownAvailability: Availability = {
  status: "check_needed",
  leadTimeMinDays: null,
  leadTimeMaxDays: null,
};
export function availabilityOf(item: {
  availability?: Availability;
}): Availability {
  return item.availability ?? unknownAvailability;
}
export function availabilityText(
  availability: Availability,
  locale: Locale,
): string {
  const { status, leadTimeMinDays: min, leadTimeMaxDays: max } = availability;
  if (status === "unavailable") return copy[locale].unavailable;
  if (status === "orderable" && min !== null && max !== null)
    return leadTime(locale, min, max);
  return copy[locale].checkNeeded;
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
