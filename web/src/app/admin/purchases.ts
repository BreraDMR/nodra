import { type OfferSource, type Price } from "./orders";
import { ApiError, errorText } from "./shared";

export const purchaseCurrencies = ["CZK", "EUR", "PLN"] as const;
export type PurchaseCurrency = (typeof purchaseCurrencies)[number];
export const purchaseStatuses = ["ordered", "received", "cancelled"] as const;
export type PurchaseStatus = (typeof purchaseStatuses)[number];
export type PurchaseAction = "receive" | "cancel";
// CZK per currency unit is kept in millionths, so CZK itself is 1 000 000
export const RATE_ONE = 1_000_000;

// One line of GET /api/admin/to-purchase
export type ToPurchaseLine = {
  itemId: string;
  orderId: string;
  orderReference: string;
  confirmedAt: string | null;
  variantId: string | null;
  name: string;
  variant: string;
  sku: string;
  quantity: number;
  // the selling price, for comparison
  unitPrice: Price;
  snapshotUnitCostCzkMinor: number | null;
  leadTimeMaxDays: number | null;
  promisedDate: string | null;
  offer: OfferSource | null;
};
export type ToPurchaseGroup = {
  // null = lines without an offer ("no source"), always the last group
  supplier: string | null;
  lines: ToPurchaseLine[];
};
export type ToPurchase = { lineCount: number; groups: ToPurchaseGroup[] };

export type PurchaseRow = {
  id: string;
  supplier: string;
  seller: string | null;
  reference: string;
  currency: PurchaseCurrency;
  status: PurchaseStatus;
  orderedAt: string;
  receivedAt: string | null;
  lineCount: number;
  goodsMinor: number;
  inboundShippingMinor: number;
  costCzkMinor: number;
};
export type PurchaseLine = {
  id: string;
  itemId: string;
  orderId: string;
  orderReference: string;
  sku: string;
  name: string;
  variant: string;
  quantity: number;
  // purchase currency
  unitPriceMinor: number;
  allocatedShippingMinor: number;
  // haléře, shipping included
  unitCostCzkMinor: number;
  snapshotUnitCostCzkMinor: number | null;
  // the order line as it is now
  procurementStatus: string;
  lineState: string;
};
export type AdminPurchase = {
  id: string;
  supplier: string;
  seller: string | null;
  reference: string;
  currency: PurchaseCurrency;
  fxRateCzk: number;
  fxRateDate: string | null;
  inboundShippingMinor: number;
  status: PurchaseStatus;
  orderedAt: string;
  receivedAt: string | null;
  cancelledAt: string | null;
  cancelReason: string | null;
  note: string | null;
  createdBy: string;
  goodsMinor: number;
  costCzkMinor: number;
  lines: PurchaseLine[];
  actions: PurchaseAction[];
};
export type CreatePurchaseBody = {
  supplier: string;
  seller: string | null;
  reference: string;
  currency: PurchaseCurrency;
  fxRateCzk: number | null;
  fxRateDate: string | null;
  inboundShippingMinor: number;
  note: string | null;
  lines: { itemId: string; unitPriceMinor: number }[];
};

export const supplierLabel = (code: string | null) =>
  code === null ? "No source" : code.replaceAll("_", " ");

// The same integer maths as the API (api/src/Purchase/Allocation.php), so the preview
// shows exactly what will be saved. BigInt because price x quantity x rate can get
// past what a JS number holds exactly.
export type AllocationLine = {
  itemId: string;
  quantity: number;
  unitPriceMinor: number;
};
export type AllocatedLine = AllocationLine & {
  allocatedShippingMinor: number;
  unitCostCzkMinor: number;
};

// a / b rounded half up, both >= 0 (Money::divRound)
function divRound(a: bigint, b: bigint): bigint {
  const two = BigInt(2);
  return (two * a + b) / (two * b);
}

export function allocatePurchase(
  lines: AllocationLine[],
  shippingMinor: number,
  fxRateCzk: number,
): AllocatedLine[] {
  // the API sorts the lines by item id before it splits (ksort), and "the largest
  // line" means the first of equals in that order
  const sorted = [...lines].sort((a, b) => {
    const x = a.itemId.toLowerCase();
    const y = b.itemId.toLowerCase();
    return x < y ? -1 : x > y ? 1 : 0;
  });
  if (!sorted.length) return [];
  const zero = BigInt(0);
  const shipping = BigInt(shippingMinor);
  const values = sorted.map(
    (l) => BigInt(l.unitPriceMinor) * BigInt(l.quantity),
  );
  const total = values.reduce((sum, v) => sum + v, zero);
  // rounded down, what's left goes to the largest line; no value at all = first line
  const shares = values.map((v) =>
    total === zero ? zero : (shipping * v) / total,
  );
  let largest = 0;
  values.forEach((v, i) => {
    if (v > values[largest]) largest = i;
  });
  shares[largest] += shipping - shares.reduce((sum, v) => sum + v, zero);
  const rate = BigInt(fxRateCzk);
  return sorted.map((line, i) => ({
    ...line,
    allocatedShippingMinor: Number(shares[i]),
    // round((unit price + shipping / quantity) x rate), in haléře
    unitCostCzkMinor: Number(
      divRound(
        (values[i] + shares[i]) * rate,
        BigInt(line.quantity) * BigInt(RATE_ONE),
      ),
    ),
  }));
}

// A refused purchase request or purchase action as one readable sentence
export function purchaseProblemText(
  e: unknown,
  attempted: string,
  skuOf: (itemId: string) => string,
): string {
  if (!(e instanceof ApiError))
    return e instanceof TypeError
      ? "No answer from the server. Check that the API is running and try again."
      : errorText(e, `${attempted} failed`);
  const line =
    typeof e.body.itemId === "string" ? skuOf(e.body.itemId) : "a line";
  switch (e.code) {
    case "line_not_purchasable":
      return `Not recorded: ${line} can't be bought any more. It is no longer a line to order of a confirmed order. The list was reloaded.`;
    case "mixed_suppliers":
      return `Not recorded: ${line} was priced from another supplier's offer. One purchase takes the lines of one supplier.`;
    case "idempotency_conflict":
      return "Not recorded: this purchase key was already used with other details. Press the button again; the next try gets a new key.";
    case "purchase_partly_received":
      return `Not cancelled: ${line} of this purchase was already received. Undo that receipt on its order first, or receive the rest.`;
    case "action_not_allowed":
      return `“${attempted}” isn't allowed in the purchase's current state. It was reloaded, so the buttons show what is possible now.`;
  }
  if (e.status === 401)
    return "The admin session ended. Reload the page and sign in again.";
  if (e.status === 403)
    return "The security token is no longer valid. Reload the page and try again.";
  return e.message;
}
