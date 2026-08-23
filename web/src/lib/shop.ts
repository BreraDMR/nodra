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
  fromPriceEur: Money | null;
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
  priceEur: Money | null;
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

// Checkout and orders (contract 0.6.1). Every amount is haléře, orders are always CZK.
export type DeliveryMethod = "pickup_andel" | "prague_personal" | "carrier_cz";
export type ContactChannel = "whatsapp" | "telegram" | "phone";
export type Fulfilment = "together" | "split";
// the methods the shop can take on handover; the receipt lists the enabled ones
export type HandoverPayment = "cash" | "bank_transfer" | "card" | "carrier_cod";
export type MethodReason =
  | "postal_code_required"
  | "invalid_postal_code"
  | "outside_prague"
  | "not_offered";
export type QuoteLine = {
  variantId: string;
  name: string;
  variant: string;
  sku: string;
  quantity: number;
  unitPrice: number;
  lineTotal: number;
  availability: Availability;
};
export type QuoteMethod = {
  method: DeliveryMethod;
  available: boolean;
  reason: MethodReason | null;
  // per shipment, null when the method doesn't work for this postal code
  fee: Money | null;
  // in the request locale
  note: string | null;
  // goods subtotal from which the method is free; null when the fee doesn't depend on it
  freeFromMinor: number | null;
};
export type QuoteShipment = {
  number: number;
  variantIds: string[];
  leadTimeMinDays: number | null;
  leadTimeMaxDays: number | null;
  fee: Money;
};
export type DeliveryOption = {
  fulfilment: Fulfilment;
  shipments: QuoteShipment[];
  shipping: Money;
  total: Money;
};
export type CheckoutQuote = {
  currency: "CZK";
  lines: QuoteLine[];
  subtotal: Money;
  methods: QuoteMethod[];
  delivery: { method: DeliveryMethod; postalCode: string | null };
  // null while the method doesn't fit the postal code or a line is unavailable
  options: { together: DeliveryOption; split: DeliveryOption | null } | null;
  canCheckout: boolean;
};
export type OrderStatus = "requested" | "confirmed" | "completed" | "cancelled";
export type PaymentStatus =
  "unpaid" | "partially_paid" | "paid" | "partially_refunded" | "refunded";
export type ShipmentStatus =
  "planned" | "scheduled" | "handed_over" | "refused" | "cancelled";
export type ReceiptItem = {
  name: string;
  variant: string;
  sku: string;
  quantity: number;
  unitPrice: number;
  lineTotal: number;
  state: "active" | "cancelled" | "returned";
  leadTimeMinDays: number | null;
  leadTimeMaxDays: number | null;
  shipment: number;
};
export type ReceiptShipment = {
  number: number;
  method: DeliveryMethod;
  status: ShipmentStatus;
  fee: Money;
  leadTimeMinDays: number | null;
  leadTimeMaxDays: number | null;
  scheduledFrom: string | null;
  scheduledTo: string | null;
};
export type OrderReceipt = {
  reference: string;
  status: OrderStatus;
  paymentStatus: PaymentStatus;
  createdAt: string;
  fulfilment: Fulfilment;
  // null on orders placed before D04
  contactChannel: ContactChannel | null;
  // the methods switched on in the shop settings right now
  paymentMethods: HandoverPayment[];
  items: ReceiptItem[];
  shipments: ReceiptShipment[];
  pickupNote: string | null;
  subtotal: Money;
  shipping: Money;
  total: Money;
  paid: Money;
  amountDue: Money;
  lookupToken: string;
};
// Contact and address of the account's latest order; empty values come as null
export type LastDelivery = {
  phone: string | null;
  contactChannel: ContactChannel | null;
  address: string | null;
  city: string | null;
  postalCode: string | null;
  district: string | null;
};
export type PointsReason = "earn" | "refund" | "correction";
export type AccountSummary = {
  name: string;
  email: string;
  points: number;
  historyPage: number;
  historyPages: number;
  historyTotal: number;
  // refund entries have negative points, a correction can go either way
  history: {
    reference: string;
    points: number;
    reason: PointsReason;
    createdAt: string;
  }[];
  lastDelivery: LastDelivery | null;
};
// Error body of every /api/ call; checkout adds a code and the extras below
export type Problem = {
  message: string;
  code?: string;
  violations?: { field: string | null; message: string }[];
  variantId?: string;
  reason?: MethodReason;
  quote?: CheckoutQuote;
};
export const locales: Locale[] = ["cs", "de", "en"];
export function isLocale(value: string): value is Locale {
  return locales.includes(value as Locale);
}
// A fresh idempotency key: 32 random hex chars. getRandomValues works on plain
// http too, randomUUID doesn't (it needs a secure context, so it breaks on LAN).
export function newIdempotencyKey(): string {
  const bytes = crypto.getRandomValues(new Uint8Array(16));
  return Array.from(bytes, (b) => b.toString(16).padStart(2, "0")).join("");
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
    pointsReasons: {
      earn: "za objednávku",
      refund: "vrácení peněz",
      correction: "oprava platby",
    } satisfies Record<PointsReason, string>,
    previous: "Předchozí",
    next: "Další",
    googleError:
      "Přihlášení přes Google zatím není dostupné. Zkontrolujte OAuth nastavení nebo použijte demo účet.",
    demoError: "Přihlášení k demo účtu se nepodařilo.",
    heroEyebrow: "VYBAVENÍ PRO KAŽDOU CESTU",
    heroTitle: "Komponenty a doplňky pro každodenní jízdu.",
    heroText:
      "Objednávejte u NODRA — poradíme s výběrem a doručíme po České republice.",
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
    place: "Odeslat objednávku",
    demo: "Objednávka je nejdřív žádost: cenu, dostupnost a termín s vámi potvrdíme přes zvolený kontakt. Platíte až při převzetí, hotově nebo převodem.",
    thankYou: "Děkujeme. Objednávku jsme přijali.",
    order: "Číslo objednávky",
    noResults: "Nic jsme nenašli. Zkuste jiný výraz.",
    quantity: "Množství",
    remove: "Odebrat",
    details: "Detaily",
    contact: "Kontaktní údaje",
    checkoutLabel: "OBJEDNÁVKA K POTVRZENÍ",
    summaryLabel: "SOUHRN OBJEDNÁVKY",
    confirmationLabel: "POTVRZENÍ OBJEDNÁVKY",
    notesLabel: "O PRODUKTU",
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
    returnsNote:
      "Vrácení do 14 dnů nebo odmítnutí zásilky — vše domluvíme zprávou.",
    consultAsk: "Nejste si jistí výběrem nebo kompatibilitou? Napište nám přes",
    // shown while no contact channel is configured (D00.3), promises no channel
    consultAskPending:
      "Výběr a kompatibilitu prověříme při potvrzení objednávky.",
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
    home: {
      catalogLabel: "NODRA / KATALOG",
      catalogTitle: "Komponenty a doplňky na kolo",
      catalogText:
        "Známé modely objednáte přímo z katalogu. Ke každé kartě uvádíme dostupnost a termín, který s vámi potvrdíme při objednávce.",
      catalogAll: "Celý obchod",
      consultLabel: "NODRA / KONZULTACE",
      consultTitle: "Nejste si jistí výběrem?",
      consultText:
        "Napište nám, poradíme s výběrem, velikostí i kompatibilitou. Konzultace je zdarma a bez závazku.",
      consultPending:
        "Kanály pro dotazy připravujeme; výběr a kompatibilitu prověříme při potvrzení objednávky.",
      deliveryLabel: "NODRA / DOPRAVA",
      deliveryTitle: "Praha — osobně v domluvený čas",
      deliveryText:
        "Po Praze doručíme osobně večer, kdy se domluvíte — 149 Kč, od 500 Kč zdarma. Dodáváme jen po České republice, cenu mimo Prahu potvrdíme při objednávce.",
      deliveryPayment: "Platíte při převzetí — hotově nebo převodem.",
      pickupLabel: "NODRA / VYZVEDNUTÍ",
      pickupTitle: "Zdarma na Andělu",
      pickupText:
        "Objednávku si můžete vyzvednout na Andělu — místo i přesný čas domluvíme zprávou.",
      installLabel: "NODRA / MONTÁŽ",
      installTitle: "Jednoduchá montáž — už brzy",
      installText:
        "Připravujeme montáž jednoduchých dílů u vás doma po Praze, večer od 17:00. Přesný seznam prací i podmínky zveřejníme, jakmile je doladíme.",
    },
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
      "Für Waren im Wert von je 100 Kč erhalten Sie nach Abschluss der Bestellung 1 Punkt. Punkte können noch nicht eingelöst werden.",
    googleSignIn: "Mit Google anmelden",
    demoSignIn: "Demo-Konto ausprobieren",
    signOut: "Abmelden",
    noPoints: "Punkte erscheinen nach Abschluss einer Bestellung.",
    pointsHistory: "Punkteverlauf",
    pointsReasons: {
      earn: "für die Bestellung",
      refund: "Erstattung",
      correction: "Zahlungskorrektur",
    } satisfies Record<PointsReason, string>,
    previous: "Zurück",
    next: "Weiter",
    googleError:
      "Die Google-Anmeldung ist noch nicht verfügbar. Prüfe die OAuth-Einstellungen oder verwende das Demo-Konto.",
    demoError: "Die Anmeldung beim Demo-Konto ist fehlgeschlagen.",
    heroEyebrow: "AUSRÜSTUNG FÜR JEDEN WEG",
    heroTitle: "Fahrradteile und Zubehör für jeden Tag.",
    heroText:
      "Bestelle bei NODRA — wir beraten dich und liefern in Tschechien.",
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
    place: "Bestellanfrage senden",
    demo: "Eine Bestellung ist zuerst eine Anfrage: Preis, Verfügbarkeit und Termin bestätigen wir mit dir über den gewählten Kontaktweg. Bezahlt wird bei der Übergabe, bar oder per Überweisung.",
    thankYou: "Danke. Deine Bestellanfrage ist eingegangen.",
    order: "Bestellnummer",
    noResults: "Keine Ergebnisse. Versuche einen anderen Begriff.",
    quantity: "Menge",
    remove: "Entfernen",
    details: "Details",
    contact: "Kontakt & Lieferung",
    checkoutLabel: "BESTELLANFRAGE",
    summaryLabel: "BESTELLÜBERSICHT",
    confirmationLabel: "BESTELLBESTÄTIGUNG",
    notesLabel: "PRODUKTDETAILS",
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
    returnsNote:
      "Rückgabe innerhalb von 14 Tagen oder die Sendung verweigern — alles klären wir per Nachricht.",
    consultAsk: "Bei der Auswahl oder Passung unsicher? Schreib uns über",
    // shown while no contact channel is configured (D00.3), promises no channel
    consultAskPending:
      "Auswahl und Passung prüfen wir bei der Bestellbestätigung.",
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
    home: {
      catalogLabel: "NODRA / KATALOG",
      catalogTitle: "Komponenten und Zubehör",
      catalogText:
        "Bekannte Modelle bestellst du direkt aus dem Katalog. Zu jeder Karte zeigen wir Verfügbarkeit und Termin, den wir mit dir bestätigen.",
      catalogAll: "Zum ganzen Shop",
      consultLabel: "NODRA / BERATUNG",
      consultTitle: "Unsicher bei der Wahl?",
      consultText:
        "Schreib uns, wir beraten dich zu Auswahl, Größe und Kompatibilität. Die Beratung ist kostenlos und unverbindlich.",
      consultPending:
        "Kontaktwege bereiten wir vor; Auswahl und Kompatibilität prüfen wir bei der Bestellbestätigung.",
      deliveryLabel: "NODRA / LIEFERUNG",
      deliveryTitle: "Prag — persönlich zur vereinbarten Zeit",
      deliveryText:
        "In Prag liefern wir persönlich am vereinbarten Abend — 149 Kč, ab 500 Kč kostenlos. Wir liefern nur innerhalb Tschechiens; den Preis außerhalb Prags bestätigen wir bei der Bestellung.",
      deliveryPayment: "Zahlung bei Übergabe — bar oder per Überweisung.",
      pickupLabel: "NODRA / ABHOLUNG",
      pickupTitle: "Kostenlos am Anděl",
      pickupText:
        "Deine Bestellung kannst du am Anděl abholen — Ort und genaue Zeit klären wir per Nachricht.",
      installLabel: "NODRA / MONTAGE",
      installTitle: "Einfache Montage — bald",
      installText:
        "Wir bereiten die Montage einfacher Teile bei dir zu Hause in Prag vor, abends ab 17:00. Die genaue Liste der Arbeiten und die Bedingungen veröffentlichen wir, sobald sie feststehen.",
    },
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
      "Earn 1 point per 100 Kč of products after an order is completed. Points cannot yet be redeemed.",
    googleSignIn: "Continue with Google",
    demoSignIn: "Try the demo account",
    signOut: "Sign out",
    noPoints: "Points appear after an order is completed.",
    pointsHistory: "Points history",
    pointsReasons: {
      earn: "for the order",
      refund: "refund",
      correction: "payment correction",
    } satisfies Record<PointsReason, string>,
    previous: "Previous",
    next: "Next",
    googleError:
      "Google sign-in is not available yet. Check the OAuth setup or use the demo account.",
    demoError: "Demo sign-in failed.",
    heroEyebrow: "GEAR FOR EVERY WAY THROUGH",
    heroTitle: "Bike components and accessories for every day.",
    heroText:
      "Order from NODRA — we help you choose and deliver across Czechia.",
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
    place: "Send order request",
    demo: "An order is a request first: we confirm the price, availability and date with you via the channel you choose. You pay on receipt, in cash or by bank transfer.",
    thankYou: "Thanks. We've got your order request.",
    order: "Order reference",
    noResults: "Nothing matched. Try another search.",
    quantity: "Quantity",
    remove: "Remove",
    details: "Details",
    contact: "Contact & delivery",
    checkoutLabel: "ORDER REQUEST",
    summaryLabel: "ORDER SUMMARY",
    confirmationLabel: "ORDER CONFIRMATION",
    notesLabel: "PRODUCT NOTES",
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
    returnsNote:
      "Return within 14 days or refuse the delivery — we'll sort it out by message.",
    consultAsk: "Not sure about the choice or the fit? Message us on",
    // shown while no contact channel is configured (D00.3), promises no channel
    consultAskPending:
      "We'll check the choice and the fit when confirming the order.",
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
    home: {
      catalogLabel: "NODRA / CATALOG",
      catalogTitle: "Bike components and accessories",
      catalogText:
        "Known models you can order straight from the catalog. Every card shows availability and the lead time we confirm with you.",
      catalogAll: "Browse the whole shop",
      consultLabel: "NODRA / ADVICE",
      consultTitle: "Not sure what fits?",
      consultText:
        "Write to us — we will help with the choice, the size and compatibility. The advice is free and comes with no obligation.",
      consultPending:
        "The contact channels are on their way; we check the choice and compatibility at order confirmation.",
      deliveryLabel: "NODRA / DELIVERY",
      deliveryTitle: "Prague — in person, at an agreed time",
      deliveryText:
        "In Prague we deliver in person on an agreed evening — 149 Kč, free from 500 Kč. We deliver within Czechia only; the price outside Prague is confirmed with the order.",
      deliveryPayment: "You pay on handover — cash or bank transfer.",
      pickupLabel: "NODRA / PICKUP",
      pickupTitle: "Free at Anděl",
      pickupText:
        "You can pick your order up at Anděl — we agree the place and the exact time by message.",
      installLabel: "NODRA / INSTALLATION",
      installTitle: "Simple installation — coming soon",
      installText:
        "We are preparing at-home installation of simple parts across Prague, evenings from 17:00. The exact list of works and the terms follow once they are settled.",
    },
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
// "3–5 dní"; one number when min and max match
export function dayCount(locale: Locale, min: number, max: number): string {
  const days = min === max ? String(max) : `${min}–${max}`;
  if (locale === "cs") {
    const word = max === 1 ? "den" : max >= 2 && max <= 4 ? "dny" : "dní";
    return `${days} ${word}`;
  }
  if (locale === "de") return `${days} ${max === 1 ? "Tag" : "Tagen"}`;
  return `${days} ${max === 1 ? "day" : "days"}`;
}
// "Doručení za 3–5 dní"
export function leadTime(locale: Locale, min: number, max: number): string {
  const days = dayCount(locale, min, max);
  if (locale === "cs") return `Doručení za ${days}`;
  if (locale === "de") return `Lieferung in ${days}`;
  return `Delivery in ${days}`;
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
export function intlLocale(locale: Locale): string {
  return locale === "cs" ? "cs-CZ" : locale === "de" ? "de-DE" : "en-GB";
}
export function money(value: Money, locale: Locale): string {
  return new Intl.NumberFormat(intlLocale(locale), {
    style: "currency",
    currency: value.currency,
  }).format(value.amount / 100);
}
export async function api<T>(path: string): Promise<T> {
  const res = await fetch(
    `${process.env.API_INTERNAL_URL || "http://127.0.0.1:8000"}${path}`,
    {
      next: { revalidate: 15 },
      // changes the cache key whenever the API contract version changes
      headers: { "x-nodra-contract": process.env.NODRA_API_CONTRACT ?? "" },
    },
  );
  if (!res.ok) throw new Error(`API ${res.status}: ${path}`);
  return res.json() as Promise<T>;
}
