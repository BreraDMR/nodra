export type AttributeOption = {
  value: string;
  labelCs: string | null;
  labelDe: string | null;
  labelEn: string | null;
};
export type AttributeDefinition = {
  key: string;
  type: "text" | "number" | "choice";
  unit: string | null;
  labelCs: string;
  labelDe: string;
  labelEn: string;
  filterable: boolean;
  options: AttributeOption[];
};
export type AdminCategory = {
  id: string;
  slug: string;
  parentId: string | null;
  depth: number;
  names: { cs: string; de: string; en: string };
  position: number;
  active: boolean;
  attributes: AttributeDefinition[];
  effectiveAttributes: AttributeDefinition[];
  productCount: number;
};
export type AttributeValues = Record<string, string>;
export type SupplierOffer = {
  id: string;
  variantId: string | null;
  supplier: string;
  seller: string | null;
  url: string;
  title: string;
  currency: string;
  priceMinor: number;
  inboundShippingMinor: number;
  fxRateCzk: number | null;
  fxRateDate: string | null;
  reportedQuantity: number | null;
  checkedAt: string;
  leadTimeMinDays: number | null;
  leadTimeMaxDays: number | null;
  verificationStatus: string;
};
export type Save = (
  url: string,
  method: string,
  body: unknown,
) => Promise<boolean | undefined>;
// Like json() but with the session CSRF token on writes. Panels that need the status
// code (409 stale, 409 overlap) use this instead of Save and handle errors themselves.
// Extra headers are for things like Idempotency-Key on payments.
export type Send = <T>(
  url: string,
  method?: string,
  body?: unknown,
  headers?: Record<string, string>,
) => Promise<T>;

export type AvailabilityStatus = "orderable" | "check_needed" | "unavailable";
export type PriceFlag = "margin_too_low" | "above_market";
export type Paged<T> = {
  items: T[];
  page: number;
  pages: number;
  total: number;
};
export type PricingRule = {
  id: string;
  categoryId: string | null;
  categorySlug: string | null;
  minCostCzkMinor: number;
  maxCostCzkMinor: number | null;
  markupBp: number;
  active: boolean;
};
export type VariantPricing = {
  variantId: string;
  productId: string;
  productName: string;
  sku: string;
  categoryId: string;
  categorySlug: string;
  priceCzk: number;
  priceEur: number;
  currentMarginBp: number | null;
  availability: {
    status: AvailabilityStatus;
    leadTimeMinDays: number | null;
    leadTimeMaxDays: number | null;
    reason: string | null;
  };
  cost: {
    offerId: string;
    supplier: string;
    seller: string | null;
    url: string;
    currency: string;
    priceMinor: number;
    inboundShippingMinor: number;
    fxRateCzk: number;
    fxRateDate: string | null;
    checkedAt: string;
    leadTimeMinDays: number | null;
    leadTimeMaxDays: number | null;
    landedCostCzk: number;
  } | null;
  rule: PricingRule | null;
  suggestion: {
    priceCzk: number;
    priceEur: number;
    markupPriceCzk: number;
    rrpCapCzk: number | null;
    rrpCapped: boolean;
    floorPriceCzk: number;
    marginBp: number;
    aboveMarket: boolean;
  } | null;
  rrpMinor: number | null;
  rrpCurrency: string | null;
  rrpSource: string | null;
  rrpCheckedAt: string | null;
  rrpCzk: number | null;
  marketPriceMinor: number | null;
  marketPriceSource: string | null;
  marketCheckedAt: string | null;
  flags: PriceFlag[];
  applicable: boolean;
};
export type PriceApplied = {
  variantId: string;
  oldPriceCzk: number;
  newPriceCzk: number;
  oldPriceEur: number;
  newPriceEur: number;
  changed: boolean;
};
export type PriceChange = {
  id: string;
  oldPriceCzk: number | null;
  newPriceCzk: number;
  oldPriceEur: number | null;
  newPriceEur: number;
  reason: "manual" | "reprice" | "import";
  changedBy: string;
  changedAt: string;
};
export type RepriceRow = {
  variantId: string;
  productId: string;
  productName: string;
  sku: string;
  categorySlug: string;
  priceCzk: number;
  priceEur: number;
  landedCostCzk: number;
  currentMarginBp: number;
  suggestedPriceCzk: number;
  suggestedPriceEur: number;
  suggestedMarginBp: number;
  marketPriceMinor: number | null;
  suggestionAboveMarket: boolean;
  flags: PriceFlag[];
  changed: boolean;
  applicable: boolean;
};
export type RepricePreview = {
  categoryId: string | null;
  variants: number;
  withoutSuggestion: number;
  rows: RepriceRow[];
};
export type PricingAlerts = {
  marginTooLow: number;
  aboveMarket: number;
  checkNeeded: number;
};
export type PricingThresholds = {
  minMarginBp: number;
  aboveMarketBp: number;
};

export const suppliers = [
  "allegro_cz",
  "allegro_pl",
  "bikeinn",
  "bike24",
  "bike_discount",
  "other",
];
export const offerStatuses = ["snapshot", "matched", "rejected"];

// Keeps the status and the stale variant list of a failed request; still an Error,
// so code that only shows e.message works as before. code and body carry the rest of
// the problem, e.g. "overpayment" with amountDueMinor.
export class ApiError extends Error {
  status: number;
  staleVariantIds: string[];
  code: string | null;
  body: Record<string, unknown>;
  constructor(
    message: string,
    status: number,
    staleVariantIds: string[],
    body: Record<string, unknown> = {},
  ) {
    super(message);
    this.status = status;
    this.staleVariantIds = staleVariantIds;
    this.code = typeof body.code === "string" ? body.code : null;
    this.body = body;
  }
}

export async function json(url: string, init?: RequestInit) {
  const r = await fetch(url, {
    ...init,
    // errors under /api/ are JSON anyway now, Accept just says what we expect
    headers: {
      Accept: "application/json",
      ...(init?.headers as Record<string, string> | undefined),
    },
    credentials: "same-origin",
  });
  const data = await r.json().catch(() => ({}));
  if (!r.ok)
    throw new ApiError(
      data?.message || data?.detail || `Request failed (${r.status})`,
      r.status,
      Array.isArray(data?.staleVariantIds) ? data.staleVariantIds : [],
      data && typeof data === "object" && !Array.isArray(data) ? data : {},
    );
  return data;
}

// The thresholds are app config, so one fetch per page load is enough — panels
// only use them to quote the numbers they already show as words
let thresholdsCache: Promise<PricingThresholds> | null = null;
export function pricingThresholds(): Promise<PricingThresholds> {
  thresholdsCache ??= json(
    "/api/admin/pricing/settings",
  ) as Promise<PricingThresholds>;
  return thresholdsCache;
}

export function percent(bp: number): string {
  return `${bp / 100} %`;
}

// The API sends {} for no values (older responses had []), so take only real string pairs
export function attributeValues(raw: unknown): AttributeValues {
  if (!raw || Array.isArray(raw) || typeof raw !== "object") return {};
  const out: AttributeValues = {};
  for (const [key, value] of Object.entries(raw)) {
    if (typeof value === "string") out[key] = value;
  }
  return out;
}

// Only keys the category knows, empty ones included so the API drops them.
// Without the category definitions we send what we have rather than wipe it.
export function attributePayload(
  values: AttributeValues,
  definitions: AttributeDefinition[] | undefined,
): AttributeValues {
  if (!definitions) return values;
  return Object.fromEntries(
    definitions.map((d) => [d.key, (values[d.key] || "").trim()]),
  );
}

export function isVisible(
  category: AdminCategory,
  byId: Map<string, AdminCategory>,
): boolean {
  let current: AdminCategory | undefined = category;
  while (current) {
    if (!current.active) return false;
    current = current.parentId ? byId.get(current.parentId) : undefined;
  }
  return true;
}

// The category itself plus everything below it, walked through parentId chains
export function subtreeIds(
  id: string,
  categories: AdminCategory[],
): Set<string> {
  const byId = new Map(categories.map((c) => [c.id, c]));
  const out = new Set<string>();
  for (const category of categories) {
    let current: AdminCategory | undefined = category;
    const seen = new Set<string>();
    while (current && !seen.has(current.id)) {
      if (current.id === id) {
        out.add(category.id);
        break;
      }
      seen.add(current.id);
      current = current.parentId ? byId.get(current.parentId) : undefined;
    }
  }
  return out;
}

export function indent(category: AdminCategory): string {
  return "   ".repeat(category.depth) + category.names.en;
}

// The API sends ISO 8601 now; the old "2026-09-26 22:00:00+00" form is still fixed up for Safari
export function parseApiDate(value: string): Date {
  const iso = value
    .trim()
    .replace(" ", "T")
    .replace(/([+-]\d\d)$/, "$1:00");
  const date = new Date(iso);
  return Number.isNaN(date.getTime()) ? new Date(value) : date;
}

export function toLocalInput(date: Date): string {
  if (Number.isNaN(date.getTime())) return "";
  const pad = (n: number) => String(n).padStart(2, "0");
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

export function formatMinor(amount: number, currency: string): string {
  return new Intl.NumberFormat("de-DE", { style: "currency", currency }).format(
    amount / 100,
  );
}

// Money typed by hand: "1 299,90" Kč -> 129990 haléře. Parsed as text and padded,
// never multiplied as a float, so 19.99 can't turn into 1998.
// Same for percent -> basis points (2 digits) and a rate like 25.315 -> millionths (6).
export function parseScaled(text: string, digits: number): number | null {
  const clean = text.replace(/[\s\u00a0\u202f]/g, "");
  if (clean === "") return null;
  const match = /^(\d+)(?:[.,](\d*))?$/.exec(clean);
  if (!match || (match[2] || "").length > digits)
    throw new Error(
      `"${text.trim()}" is not a number with up to ${digits} decimals`,
    );
  const value = Number(match[1] + (match[2] || "").padEnd(digits, "0"));
  if (!Number.isSafeInteger(value))
    throw new Error(`"${text.trim()}" is too large`);
  return value;
}
// for pattern= on inputs, so the browser stops a typo before we try to parse it
export const moneyPattern = "\\s*\\d[\\d\\s]*([.,]\\d{0,2})?\\s*";
export const moneyHint = "A number with up to 2 decimals, e.g. 1290 or 51.60";
export const parseMinor = (text: string) => parseScaled(text, 2);
export const parseBp = (text: string) => parseScaled(text, 2);
export const parseRate = (text: string) => parseScaled(text, 6);

// The other way, for filling a form: 129990 -> "1299.9", 25315000 -> "25.315"
export function scaledText(value: number | null, digits: number): string {
  if (value === null) return "";
  const sign = value < 0 ? "-" : "";
  const raw = String(Math.abs(value)).padStart(digits + 1, "0");
  const whole = raw.slice(0, raw.length - digits);
  const fraction = raw.slice(raw.length - digits).replace(/0+$/, "");
  return sign + whole + (fraction ? `.${fraction}` : "");
}
// money keeps both decimals once it has any: "1299.90", but "1690" stays "1690"
export function minorText(value: number | null): string {
  const text = scaledText(value, 2);
  const dot = text.indexOf(".");
  return dot === -1 ? text : text.padEnd(dot + 3, "0");
}

// non-breaking space so "28.79 %" never splits in a narrow cell
export function formatBp(bp: number): string {
  return `${scaledText(bp, 2)}\u00a0%`;
}
export function formatRate(rate: number): string {
  return scaledText(rate, 6);
}
export function formatCzk(minor: number): string {
  return formatMinor(minor, "CZK");
}

// "2026-09-26" as a day, whatever the browser time zone is
export function formatDay(value: string | null): string {
  if (!value) return "—";
  const [y, m, d] = value.slice(0, 10).split("-").map(Number);
  const date = new Date(Date.UTC(y, m - 1, d));
  if (Number.isNaN(date.getTime())) return value;
  return date.toLocaleDateString("en-GB", {
    day: "numeric",
    month: "short",
    year: "numeric",
    timeZone: "UTC",
  });
}

// "28 Sept 2026, 14:05" in the browser's time zone
export function formatDateTime(value: string | null): string {
  if (!value) return "—";
  const date = parseApiDate(value);
  if (Number.isNaN(date.getTime())) return value;
  return date.toLocaleString("en-GB", {
    dateStyle: "medium",
    timeStyle: "short",
  });
}

export function formatDays(min: number | null, max: number | null): string {
  if (min === null || max === null) return "—";
  return min === max
    ? `${max} ${max === 1 ? "day" : "days"}`
    : `${min}–${max} days`;
}

export const availabilityLabels: Record<AvailabilityStatus, string> = {
  orderable: "Orderable",
  check_needed: "Check needed",
  unavailable: "Unavailable",
};
export const availabilityReasons: Record<string, string> = {
  no_offers: "No supplier offers for this variant",
  sold_out: "Every offer is sold out or rejected",
  not_matched: "Offers aren't matched to this exact variant yet",
  stale: "The matched offer wasn't checked recently enough",
  missing_fx: "The offer currency has no exchange rate",
  unknown_lead_time: "The best offer has no lead time",
};
export const flagLabels: Record<PriceFlag, string> = {
  margin_too_low: "Margin too low",
  above_market: "Above market",
};

// "Components / Tyres", or the slug when the category isn't loaded
export function categoryPath(
  id: string | null,
  categories: AdminCategory[],
  fallback = "",
): string {
  const byId = new Map(categories.map((c) => [c.id, c]));
  const names: string[] = [];
  let current = id ? byId.get(id) : undefined;
  while (current && names.length < 20) {
    names.unshift(current.names.en);
    current = current.parentId ? byId.get(current.parentId) : undefined;
  }
  return names.join(" / ") || fallback;
}

// Band start is inclusive, end exclusive, so "300 – under 1000 Kč"
export function costBand(rule: {
  minCostCzkMinor: number;
  maxCostCzkMinor: number | null;
}): string {
  return rule.maxCostCzkMinor === null
    ? `${formatCzk(rule.minCostCzkMinor)} and up`
    : `${formatCzk(rule.minCostCzkMinor)} – under ${formatCzk(rule.maxCostCzkMinor)}`;
}

// A fresh Idempotency-Key: prefix + 32 random hex chars. getRandomValues works on
// plain http too, randomUUID doesn't
export function newKey(prefix: string): string {
  const bytes = crypto.getRandomValues(new Uint8Array(16));
  return (
    prefix +
    "-" +
    Array.from(bytes, (b) => b.toString(16).padStart(2, "0")).join("")
  );
}

export function errorText(e: unknown, fallback: string): string {
  return e instanceof Error && e.message ? e.message : fallback;
}
