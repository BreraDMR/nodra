// Public shop contacts and seller details, all optional.
// Nothing here is shown until the env value is set and looks valid.
// NEXT_PUBLIC_ values are baked in at build time, so a change needs a rebuild.

function clean(value: string | undefined): string {
  return value?.trim() || "";
}

// "+420 123 456 789" and "420123456789" both end up as plain digits
const whatsapp = clean(process.env.NEXT_PUBLIC_SHOP_WHATSAPP).replace(
  /[\s()+-]/g,
  "",
);
const telegram = clean(process.env.NEXT_PUBLIC_SHOP_TELEGRAM).replace(/^@/, "");
const email = clean(process.env.NEXT_PUBLIC_SHOP_EMAIL);

export type ContactLinks = {
  whatsapp: string | null;
  telegram: string | null;
  email: { href: string; label: string } | null;
};

export const contactLinks: ContactLinks = {
  whatsapp: /^\d{8,15}$/.test(whatsapp) ? `https://wa.me/${whatsapp}` : null,
  telegram: /^[A-Za-z0-9_]{5,32}$/.test(telegram)
    ? `https://t.me/${telegram}`
    : null,
  email: /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)
    ? { href: `mailto:${email}`, label: email }
    : null,
};

export const hasMessengers = Boolean(
  contactLinks.whatsapp || contactLinks.telegram,
);
export const hasContacts = Boolean(hasMessengers || contactLinks.email);

const ico = clean(process.env.NEXT_PUBLIC_SELLER_ICO).replace(/\s/g, "");

export const seller = {
  name: clean(process.env.NEXT_PUBLIC_SELLER_NAME),
  // Czech IČO is always 8 digits, anything else stays hidden
  ico: /^\d{8}$/.test(ico) ? ico : "",
  address: clean(process.env.NEXT_PUBLIC_SELLER_ADDRESS),
};

export const hasSeller = Boolean(seller.name || seller.ico || seller.address);
