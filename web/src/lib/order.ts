// Wording of the order request flow: checkout, delivery choice and the order page.
// Kept apart from the catalogue copy in shop.ts, same cs/de/en shape.
import {
  dayCount,
  intlLocale,
  type ContactChannel,
  type DeliveryMethod,
  type Locale,
  type MethodReason,
  type OrderStatus,
  type PaymentStatus,
  type ShipmentStatus,
} from "./shop";

export const channels: ContactChannel[] = ["whatsapp", "telegram", "phone"];

export const orderCopy = {
  cs: {
    contactHeading: "Kontakt",
    deliveryHeading: "Převzetí",
    consentHeading: "Souhlas",
    phone: "Telefon",
    channel: "Kde vás máme kontaktovat",
    channels: {
      whatsapp: "WhatsApp",
      telegram: "Telegram",
      phone: "Telefonát",
    } satisfies Record<ContactChannel, string>,
    via: {
      whatsapp: "přes WhatsApp",
      telegram: "přes Telegram",
      phone: "telefonicky",
      unknown: "přes kontakt, který jste zvolili",
    },
    method: "Způsob převzetí",
    methods: {
      pickup_andel: "Osobní odběr na Andělu",
      prague_personal: "Doručení po Praze",
      carrier_cz: "Doprava přepravcem po Česku",
    } satisfies Record<DeliveryMethod, string>,
    free: "zdarma",
    pickupNote: "Místo a čas předání na Andělu domluvíme zprávou.",
    pragueNote:
      "Doručíme osobně na adresu v Praze (PSČ 100 00–199 99). Poplatek platí za každou zásilku.",
    reasons: {
      postal_code_required: "Zadejte PSČ níže. Osobně doručujeme jen v Praze.",
      invalid_postal_code: "PSČ má tvar 110 00.",
      outside_prague:
        "Toto PSČ je mimo Prahu. Osobně doručujeme jen na pražská PSČ 100 00–199 99 a doprava přepravcem po Česku zatím není k dispozici. Vyberte prosím osobní odběr na Andělu.",
      not_offered: "Tento způsob převzetí teď nenabízíme.",
    } satisfies Record<MethodReason, string>,
    reasonsShort: {
      postal_code_required: "zadejte PSČ",
      invalid_postal_code: "neplatné PSČ",
      outside_prague: "jen v Praze",
      not_offered: "nedostupné",
    } satisfies Record<MethodReason, string>,
    carrierOff: "Doprava přepravcem mimo Prahu zatím není k dispozici.",
    city: "Město",
    district: "Městská část (nepovinné)",
    note: "Poznámka k předání (nepovinné)",
    fulfilment: "Jak zboží předat",
    fulfilmentHelp:
      "Zboží nedorazí najednou. Můžete počkat na všechno, nebo převzít po částech, co je připravené.",
    together: "Vše najednou",
    split: "Po částech",
    shipment: "Zásilka {n}",
    expected: "Očekáváme za {days}",
    dateLater: "Termín potvrdíme",
    privacyBefore:
      "Souhlasím se zpracováním osobních údajů pro vyřízení objednávky podle ",
    privacyLink: "zásad ochrany osobních údajů",
    privacyAfter: ".",
    marketing:
      "Chci občas dostávat novinky a nabídky NODRA e-mailem (nepovinné).",
    czkNote: "",
    calculating: "Počítáme cenu…",
    quoteFailed: "Cenu se nepodařilo spočítat.",
    retry: "Zkusit znovu",
    unavailableLines:
      "Některé zboží teď nelze objednat. Odeberte ho z košíku, abyste mohli pokračovat.",
    quoteChanged:
      "Cena, dostupnost nebo doprava se mezitím změnily. Zkontrolujte novou celkovou částku a odešlete objednávku znovu.",
    splitUnavailable:
      "Všechno zboží dorazí najednou, proto ho předáme vcelku. Zkontrolujte souhrn a odešlete objednávku znovu.",
    productGone: "{item} už nenabízíme. Odeberte ho prosím z košíku.",
    sendFailed: "Objednávku se nepodařilo odeslat. Zkuste to prosím znovu.",
    checkFields: "Zkontrolujte prosím označená pole.",
    fieldErrors: {
      name: "Vyplňte jméno a příjmení.",
      email: "Zadejte platný e-mail.",
      phone:
        "Zadejte telefon s předvolbou, např. +420 777 123 456, nebo 9 číslic. Mezery můžete použít, pomlčky ne.",
      contactChannel: "Vyberte, kde vás máme kontaktovat.",
      country: "Doručujeme pouze v České republice.",
      address: "Vyplňte ulici a číslo.",
      city: "Vyplňte město.",
      postalCode: "Zadejte PSČ ve tvaru 110 00.",
      district: "Městská část může mít nejvýš 120 znaků.",
      deliveryNote: "Poznámka může mít nejvýš 500 znaků.",
      privacy:
        "Bez souhlasu se zpracováním osobních údajů objednávku nemůžeme přijmout.",
    },
    accountEmail:
      "Jste přihlášeni jako {email}. Objednávku odešlete pod tímto e-mailem, nebo se odhlaste.",
    orderStatus: "Stav objednávky",
    payment: "Platba",
    sent: "Odesláno",
    itemsHeading: "Zboží",
    shipmentsHeading: "Zásilky",
    totalsHeading: "Platba při převzetí",
    paid: "Uhrazeno",
    amountDue: "Zbývá zaplatit",
    statuses: {
      requested: "Čeká na potvrzení",
      confirmed: "Potvrzeno",
      completed: "Dokončeno",
      cancelled: "Zrušeno",
    } satisfies Record<OrderStatus, string>,
    statusText: {
      requested: "Cenu, dostupnost a termín s vámi potvrdíme {via}.",
      confirmed:
        "Objednávka je potvrzená. Termín předání uvidíte u zásilek, jakmile ho domluvíme.",
      completed: "Objednávka je předaná a zaplacená. Děkujeme!",
      cancelled: "Objednávka je zrušená. Pokud máte dotaz, napište nám.",
    } satisfies Record<OrderStatus, string>,
    payOnReceipt: "Platíte při převzetí, hotově nebo převodem.",
    paymentStatuses: {
      unpaid: "Nezaplaceno",
      partially_paid: "Částečně zaplaceno",
      paid: "Zaplaceno",
      partially_refunded: "Částečně vráceno",
      refunded: "Vráceno",
    } satisfies Record<PaymentStatus, string>,
    lineStates: { cancelled: "zrušeno", returned: "vráceno" },
    shipmentStatuses: {
      planned: "Připravujeme",
      scheduled: "Termín domluven",
      handed_over: "Předáno",
      refused: "Nepřevzato",
      cancelled: "Zrušeno",
    } satisfies Record<ShipmentStatus, string>,
  },
  de: {
    contactHeading: "Kontakt",
    deliveryHeading: "Übergabe",
    consentHeading: "Einwilligung",
    phone: "Telefon",
    channel: "Wie sollen wir dich kontaktieren?",
    channels: {
      whatsapp: "WhatsApp",
      telegram: "Telegram",
      phone: "Anruf",
    } satisfies Record<ContactChannel, string>,
    via: {
      whatsapp: "per WhatsApp",
      telegram: "per Telegram",
      phone: "telefonisch",
      unknown: "über den gewählten Kontaktweg",
    },
    method: "Übergabe",
    methods: {
      pickup_andel: "Abholung am Anděl",
      prague_personal: "Persönliche Lieferung in Prag",
      carrier_cz: "Versand innerhalb Tschechiens",
    } satisfies Record<DeliveryMethod, string>,
    free: "kostenlos",
    pickupNote:
      "Ort und Zeit der Übergabe am Anděl vereinbaren wir per Nachricht.",
    pragueNote:
      "Wir liefern persönlich an eine Prager Adresse (PLZ 100 00–199 99). Die Gebühr gilt pro Sendung.",
    reasons: {
      postal_code_required:
        "Gib unten deine Postleitzahl ein. Persönlich liefern wir nur in Prag.",
      invalid_postal_code: "Die Postleitzahl hat die Form 110 00.",
      outside_prague:
        "Diese Postleitzahl liegt außerhalb Prags. Persönlich liefern wir nur an Prager PLZ 100 00–199 99, und Versand innerhalb Tschechiens bieten wir noch nicht an. Bitte wähle die Abholung am Anděl.",
      not_offered: "Diese Übergabeart bieten wir derzeit nicht an.",
    } satisfies Record<MethodReason, string>,
    reasonsShort: {
      postal_code_required: "PLZ eingeben",
      invalid_postal_code: "ungültige PLZ",
      outside_prague: "nur in Prag",
      not_offered: "nicht verfügbar",
    } satisfies Record<MethodReason, string>,
    carrierOff: "Versand außerhalb Prags ist noch nicht verfügbar.",
    city: "Stadt",
    district: "Stadtteil (optional)",
    note: "Hinweis zur Übergabe (optional)",
    fulfilment: "Wie sollen wir übergeben?",
    fulfilmentHelp:
      "Die Artikel kommen nicht gleichzeitig an. Du kannst auf alles warten oder in Teilen übernehmen, was schon da ist.",
    together: "Alles zusammen",
    split: "In Teilen",
    shipment: "Sendung {n}",
    expected: "Voraussichtlich in {days}",
    dateLater: "Termin bestätigen wir",
    privacyBefore:
      "Ich bin mit der Verarbeitung meiner personenbezogenen Daten zur Abwicklung der Bestellung gemäß der ",
    privacyLink: "Datenschutzerklärung",
    privacyAfter: " einverstanden.",
    marketing:
      "Ich möchte gelegentlich Neuigkeiten und Angebote von NODRA per E-Mail erhalten (optional).",
    czkNote:
      "Bestellungen laufen immer in Tschechischen Kronen (CZK). Euro-Preise im Shop dienen nur zur Orientierung.",
    calculating: "Preis wird berechnet…",
    quoteFailed: "Der Preis konnte nicht berechnet werden.",
    retry: "Erneut versuchen",
    unavailableLines:
      "Einige Artikel können gerade nicht bestellt werden. Entferne sie aus dem Warenkorb, um fortzufahren.",
    quoteChanged:
      "Preis, Verfügbarkeit oder Lieferung haben sich inzwischen geändert. Prüfe den neuen Gesamtbetrag und sende die Bestellung erneut.",
    splitUnavailable:
      "Alle Artikel kommen gleichzeitig an, deshalb übergeben wir alles zusammen. Prüfe die Übersicht und sende die Bestellung erneut.",
    productGone:
      "{item} bieten wir nicht mehr an. Bitte entferne den Artikel aus dem Warenkorb.",
    sendFailed:
      "Die Bestellung konnte nicht gesendet werden. Bitte versuche es erneut.",
    checkFields: "Bitte prüfe die markierten Felder.",
    fieldErrors: {
      name: "Bitte gib deinen vollständigen Namen ein.",
      email: "Bitte gib eine gültige E-Mail-Adresse ein.",
      phone:
        "Bitte gib die Telefonnummer mit Vorwahl ein, z. B. +420 777 123 456, oder 9 Ziffern. Leerzeichen sind erlaubt, Bindestriche nicht.",
      contactChannel: "Bitte wähle, wie wir dich kontaktieren sollen.",
      country: "Wir liefern nur innerhalb Tschechiens.",
      address: "Bitte gib Straße und Hausnummer ein.",
      city: "Bitte gib die Stadt ein.",
      postalCode: "Bitte gib die Postleitzahl in der Form 110 00 ein.",
      district: "Der Stadtteil darf höchstens 120 Zeichen haben.",
      deliveryNote: "Der Hinweis darf höchstens 500 Zeichen haben.",
      privacy:
        "Ohne Einwilligung in die Datenverarbeitung können wir die Bestellung nicht annehmen.",
    },
    accountEmail:
      "Du bist als {email} angemeldet. Sende die Bestellung mit dieser E-Mail oder melde dich ab.",
    orderStatus: "Bestellstatus",
    payment: "Zahlung",
    sent: "Gesendet",
    itemsHeading: "Artikel",
    shipmentsHeading: "Sendungen",
    totalsHeading: "Zahlung bei Übergabe",
    paid: "Bezahlt",
    amountDue: "Noch zu zahlen",
    statuses: {
      requested: "Wartet auf Bestätigung",
      confirmed: "Bestätigt",
      completed: "Abgeschlossen",
      cancelled: "Storniert",
    } satisfies Record<OrderStatus, string>,
    statusText: {
      requested:
        "Preis, Verfügbarkeit und Termin bestätigen wir mit dir {via}.",
      confirmed:
        "Die Bestellung ist bestätigt. Den Übergabetermin siehst du bei den Sendungen, sobald wir ihn vereinbart haben.",
      completed: "Die Bestellung ist übergeben und bezahlt. Danke!",
      cancelled: "Die Bestellung wurde storniert. Bei Fragen schreib uns.",
    } satisfies Record<OrderStatus, string>,
    payOnReceipt: "Bezahlt wird bei der Übergabe, bar oder per Überweisung.",
    paymentStatuses: {
      unpaid: "Nicht bezahlt",
      partially_paid: "Teilweise bezahlt",
      paid: "Bezahlt",
      partially_refunded: "Teilweise erstattet",
      refunded: "Erstattet",
    } satisfies Record<PaymentStatus, string>,
    lineStates: { cancelled: "storniert", returned: "zurückgegeben" },
    shipmentStatuses: {
      planned: "In Vorbereitung",
      scheduled: "Termin vereinbart",
      handed_over: "Übergeben",
      refused: "Nicht angenommen",
      cancelled: "Storniert",
    } satisfies Record<ShipmentStatus, string>,
  },
  en: {
    contactHeading: "Contact",
    deliveryHeading: "Handover",
    consentHeading: "Consent",
    phone: "Phone",
    channel: "How should we contact you?",
    channels: {
      whatsapp: "WhatsApp",
      telegram: "Telegram",
      phone: "Phone call",
    } satisfies Record<ContactChannel, string>,
    via: {
      whatsapp: "via WhatsApp",
      telegram: "via Telegram",
      phone: "by phone",
      unknown: "via the channel you chose",
    },
    method: "Delivery or pickup",
    methods: {
      pickup_andel: "Pickup at Anděl",
      prague_personal: "Personal delivery in Prague",
      carrier_cz: "Carrier delivery within Czechia",
    } satisfies Record<DeliveryMethod, string>,
    free: "free",
    pickupNote: "We agree the place and time at Anděl by message.",
    pragueNote:
      "We deliver in person to an address in Prague (postal codes 100 00–199 99). The fee is per shipment.",
    reasons: {
      postal_code_required:
        "Enter your postal code below. We only deliver in person within Prague.",
      invalid_postal_code: "A postal code looks like 110 00.",
      outside_prague:
        "This postal code is outside Prague. We deliver in person to Prague postal codes 100 00–199 99 only, and carrier delivery within Czechia isn't available yet. Please choose pickup at Anděl.",
      not_offered: "We don't offer this option right now.",
    } satisfies Record<MethodReason, string>,
    reasonsShort: {
      postal_code_required: "enter postal code",
      invalid_postal_code: "invalid postal code",
      outside_prague: "Prague only",
      not_offered: "not available",
    } satisfies Record<MethodReason, string>,
    carrierOff: "Carrier delivery outside Prague isn't available yet.",
    city: "City",
    district: "District (optional)",
    note: "Note for the handover (optional)",
    fulfilment: "How should we hand it over?",
    fulfilmentHelp:
      "Your items won't all arrive at once. You can wait for everything or take what's ready in parts.",
    together: "All together",
    split: "In parts",
    shipment: "Shipment {n}",
    expected: "Expected in {days}",
    dateLater: "We'll confirm the date",
    privacyBefore:
      "I agree to the processing of my personal data to handle this order under the ",
    privacyLink: "privacy policy",
    privacyAfter: ".",
    marketing: "Send me occasional NODRA news and offers by email (optional).",
    czkNote:
      "Orders are always in Czech koruna (CZK). Euro prices in the shop are for reference only.",
    calculating: "Calculating the price…",
    quoteFailed: "We couldn't calculate the price.",
    retry: "Try again",
    unavailableLines:
      "Some items can't be ordered right now. Remove them from your basket to continue.",
    quoteChanged:
      "The price, availability or delivery changed in the meantime. Check the new total and send your order again.",
    splitUnavailable:
      "Everything arrives at the same time, so we'll hand it over together. Check the summary and send your order again.",
    productGone:
      "{item} is no longer offered. Please remove it from your basket.",
    sendFailed: "Your order couldn't be sent. Please try again.",
    checkFields: "Please check the highlighted fields.",
    fieldErrors: {
      name: "Please enter your full name.",
      email: "Please enter a valid email address.",
      phone:
        "Enter a phone number with the country code, like +420 777 123 456, or 9 digits. Spaces are fine, dashes aren't.",
      contactChannel: "Please choose how we should contact you.",
      country: "We deliver within Czechia only.",
      address: "Please enter your street address.",
      city: "Please enter your city.",
      postalCode: "Enter a postal code like 110 00.",
      district: "The district can have at most 120 characters.",
      deliveryNote: "The note can have at most 500 characters.",
      privacy:
        "We can't accept the order without your consent to process personal data.",
    },
    accountEmail:
      "You're signed in as {email}. Send the order with that email or sign out.",
    orderStatus: "Order status",
    payment: "Payment",
    sent: "Sent",
    itemsHeading: "Items",
    shipmentsHeading: "Shipments",
    totalsHeading: "Payment on receipt",
    paid: "Paid",
    amountDue: "Still to pay",
    statuses: {
      requested: "Waiting for confirmation",
      confirmed: "Confirmed",
      completed: "Completed",
      cancelled: "Cancelled",
    } satisfies Record<OrderStatus, string>,
    statusText: {
      requested:
        "We'll confirm the price, availability and date with you {via}.",
      confirmed:
        "Your order is confirmed. The handover window shows under shipments once we've agreed it.",
      completed: "Your order is handed over and paid. Thank you!",
      cancelled:
        "This order was cancelled. If you have a question, message us.",
    } satisfies Record<OrderStatus, string>,
    payOnReceipt: "You pay on receipt, in cash or by bank transfer.",
    paymentStatuses: {
      unpaid: "Not paid yet",
      partially_paid: "Partly paid",
      paid: "Paid",
      partially_refunded: "Partly refunded",
      refunded: "Refunded",
    } satisfies Record<PaymentStatus, string>,
    lineStates: { cancelled: "cancelled", returned: "returned" },
    shipmentStatuses: {
      planned: "Being prepared",
      scheduled: "Date agreed",
      handed_over: "Handed over",
      refused: "Not accepted",
      cancelled: "Cancelled",
    } satisfies Record<ShipmentStatus, string>,
  },
};

// "Očekáváme za 3–5 dní", or "we'll confirm" while a date is unknown
export function expectedText(
  locale: Locale,
  min: number | null,
  max: number | null,
): string {
  const o = orderCopy[locale];
  if (min === null || max === null) return o.dateLater;
  return o.expected.replace("{days}", dayCount(locale, min, max));
}

// The API sends the pickup note from its settings, in English for now.
// Until it's localized, the default note is said in the page language;
// anything the owner writes there instead is shown as it is.
const defaultPickupNote = "Anděl, place and time agreed by message";
export function pickupNoteText(note: string | null, locale: Locale): string {
  return !note || note === defaultPickupNote
    ? orderCopy[locale].pickupNote
    : note;
}

// Agreed handover window: "12. 10. 2026 14:00–16:00", both dates when it spans days
export function windowText(from: string, to: string, locale: Locale): string {
  const start = new Date(from);
  const end = new Date(to);
  const day = new Intl.DateTimeFormat(intlLocale(locale), {
    dateStyle: "medium",
  });
  const time = new Intl.DateTimeFormat(intlLocale(locale), {
    timeStyle: "short",
  });
  return day.format(start) === day.format(end)
    ? `${day.format(start)} ${time.format(start)}–${time.format(end)}`
    : `${day.format(start)} ${time.format(start)} – ${day.format(end)} ${time.format(end)}`;
}

export function dateText(value: string, locale: Locale): string {
  return new Intl.DateTimeFormat(intlLocale(locale), {
    dateStyle: "medium",
    timeStyle: "short",
  }).format(new Date(value));
}
