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

export const suppliers = [
  "allegro_cz",
  "allegro_pl",
  "bikeinn",
  "bike24",
  "bike_discount",
  "other",
];
export const offerStatuses = ["snapshot", "matched", "rejected"];

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
    throw Error(data.message || data.detail || `Request failed (${r.status})`);
  return data;
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
