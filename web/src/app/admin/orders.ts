import {
  ApiError,
  errorText,
  formatDateTime,
  formatDays,
  formatMinor,
  type AvailabilityStatus,
} from "./shared";

export type Price = { amount: number; currency: string };

export const orderStatuses = [
  "requested",
  "confirmed",
  "completed",
  "cancelled",
] as const;
export type OrderStatus = (typeof orderStatuses)[number];
export const paymentStatuses = [
  "unpaid",
  "partially_paid",
  "paid",
  "partially_refunded",
  "refunded",
] as const;
export type PaymentStatus = (typeof paymentStatuses)[number];
export const agreementChannels = [
  "whatsapp",
  "telegram",
  "phone",
  "email",
  "in_person",
] as const;
export type AgreementChannel = (typeof agreementChannels)[number];
export const paymentMethods = [
  "cash",
  "bank_transfer",
  "card",
  "carrier_cod",
] as const;
export type PaymentMethod = (typeof paymentMethods)[number];
export type PaymentKind = "payment" | "refund";

export type OrderAction =
  "confirm" | "cancel" | "record_payment" | "record_refund";
export type ItemAction =
  | "change_terms"
  | "mark_ordered"
  | "mark_received"
  | "mark_failed"
  | "cancel"
  | "replace"
  | "return";
export type ShipmentAction = "schedule" | "hand_over" | "refuse";

// One row of GET /api/admin/orders, also used by the dashboard's recent orders
export type OrderRow = {
  id: string;
  reference: string;
  status: OrderStatus;
  paymentStatus: PaymentStatus;
  customerName: string;
  email: string;
  phone: string | null;
  country: string;
  total: Price;
  createdAt: string;
};
export type Agreement = { channel: AgreementChannel; note: string };
export type AdminOrderItem = {
  id: string;
  variantId: string | null;
  name: string;
  variant: string;
  sku: string;
  quantity: number;
  unitPrice: number;
  lineTotal: number;
  state: "active" | "cancelled" | "returned";
  procurementStatus:
    "from_stock" | "to_order" | "ordered" | "received" | "failed";
  supplierReference: string | null;
  shipmentId: string;
  replacesItemId: string | null;
  // checkout snapshot, null on orders from before D02
  availabilityStatus: AvailabilityStatus | null;
  leadTimeMinDays: number | null;
  leadTimeMaxDays: number | null;
  supplierOfferId: string | null;
  unitCostCzkMinor: number | null;
  actions: ItemAction[];
};
export type AdminShipment = {
  id: string;
  number: number;
  method: string;
  status: "planned" | "scheduled" | "handed_over" | "refused" | "cancelled";
  fee: Price;
  leadTimeMinDays: number | null;
  leadTimeMaxDays: number | null;
  scheduledFrom: string | null;
  scheduledTo: string | null;
  handedOverAt: string | null;
  ready: boolean;
  itemIds: string[];
  actions: ShipmentAction[];
};
export type AdminPayment = {
  id: string;
  kind: PaymentKind;
  method: PaymentMethod;
  amount: Price;
  shipmentId: string | null;
  recordedAt: string;
  recordedBy: string;
  note: string | null;
};
export type OrderEvent = {
  id: string;
  type: string;
  data: Record<string, unknown>;
  actor: string;
  createdAt: string;
};
export type AdminOrder = {
  id: string;
  reference: string;
  status: OrderStatus;
  paymentStatus: PaymentStatus;
  locale: string;
  currency: string;
  fulfilment: "together" | "split";
  createdAt: string;
  customer: {
    name: string;
    email: string;
    phone: string | null;
    contactChannel: "whatsapp" | "telegram" | "phone" | null;
    country: string;
    address: string;
    city: string;
    postalCode: string;
    district: string;
    deliveryNote: string | null;
    accountId: string | null;
  };
  consents: {
    privacyConsentedAt: string | null;
    privacyTextVersion: string | null;
    marketing: boolean;
    marketingConsentedAt: string | null;
  };
  items: AdminOrderItem[];
  shipments: AdminShipment[];
  payments: AdminPayment[];
  events: OrderEvent[];
  subtotal: Price;
  shipping: Price;
  total: Price;
  paid: Price;
  amountDue: Price;
  refundDue: Price;
  lookupToken: string;
  actions: OrderAction[];
};

// Readable names for API values; a value the admin doesn't know yet shows as it is
const statusLabels: Record<string, string> = {
  requested: "Requested",
  confirmed: "Confirmed",
  completed: "Completed",
  cancelled: "Cancelled",
  unpaid: "Unpaid",
  partially_paid: "Partially paid",
  paid: "Paid",
  partially_refunded: "Partially refunded",
  refunded: "Refunded",
  planned: "Planned",
  scheduled: "Scheduled",
  handed_over: "Handed over",
  refused: "Refused",
  from_stock: "Own stock",
  to_order: "To order",
  ordered: "Ordered",
  received: "Received",
  failed: "Failed",
  active: "Active",
  returned: "Returned",
};
export const statusLabel = (value: string) =>
  statusLabels[value] ?? value.replaceAll("_", " ");

export const deliveryMethodLabels: Record<string, string> = {
  pickup_andel: "Pickup at Anděl",
  prague_personal: "Prague, in person",
  carrier_cz: "Carrier, Czechia",
};
export const paymentMethodLabels: Record<PaymentMethod, string> = {
  cash: "Cash",
  bank_transfer: "Bank transfer",
  card: "Card",
  carrier_cod: "Carrier cash on delivery",
};
export const channelLabels: Record<AgreementChannel, string> = {
  whatsapp: "WhatsApp",
  telegram: "Telegram",
  phone: "Phone call",
  email: "Email",
  in_person: "In person",
};
export const orderActionLabels: Record<OrderAction, string> = {
  confirm: "Confirm order",
  cancel: "Cancel order",
  record_payment: "Record payment",
  record_refund: "Record refund",
};
export const itemActionLabels: Record<ItemAction, string> = {
  change_terms: "Change terms",
  mark_ordered: "Mark ordered",
  mark_received: "Mark received",
  mark_failed: "Mark failed",
  cancel: "Cancel line",
  replace: "Replace",
  return: "Take back",
};
export const shipmentActionLabels: Record<ShipmentAction, string> = {
  schedule: "Schedule",
  hand_over: "Hand over",
  refuse: "Customer refused",
};
const eventLabels: Record<string, string> = {
  placed: "Order request placed",
  confirmed: "Confirmed with the customer",
  terms_changed: "Line terms changed",
  item_ordered: "Line ordered from the supplier",
  item_received: "Line goods received",
  item_failed: "Line procurement failed",
  item_cancelled: "Line cancelled",
  replacement_added: "Replacement added",
  item_returned: "Line taken back",
  shipment_scheduled: "Shipment scheduled",
  shipment_handed_over: "Shipment handed over",
  shipment_refused: "Shipment refused",
  shipment_cancelled: "Shipment cancelled",
  payment_recorded: "Payment recorded",
  refund_recorded: "Refund recorded",
  cancelled: "Order cancelled",
  completed: "Order completed",
  loyalty_adjusted: "Loyalty points adjusted",
  migrated: "Moved over from the old order model",
};
export const eventLabel = (type: string) =>
  eventLabels[type] ?? type.replaceAll("_", " ");

export const formatPrice = (price: Price) =>
  formatMinor(price.amount, price.currency);

// Lines with an unknown lead time wait for the shop to confirm a date
export function leadTimeText(min: number | null, max: number | null): string {
  return min === null || max === null ? "to confirm" : formatDays(min, max);
}

// What the customer said yes to, e.g. "WhatsApp: 1 290 Kč, Thursday evening"
function agreementText(value: unknown): string | null {
  if (!value || typeof value !== "object") return null;
  const { channel, note } = value as { channel?: string; note?: string };
  const via = channelLabels[channel as AgreementChannel] ?? channel ?? "?";
  return `agreed via ${via}${note ? `: “${note}”` : ""}`;
}

type Terms = {
  unitPriceMinor?: number | null;
  leadTimeMinDays?: number | null;
  leadTimeMaxDays?: number | null;
};
function termsText(before: unknown, after: unknown, currency: string) {
  if (!before || !after || typeof before !== "object") return null;
  const a = before as Terms;
  const b = after as Terms;
  const parts: string[] = [];
  if (a.unitPriceMinor !== b.unitPriceMinor)
    parts.push(
      `unit price ${formatMinor(a.unitPriceMinor ?? 0, currency)} → ${formatMinor(b.unitPriceMinor ?? 0, currency)}`,
    );
  if (
    a.leadTimeMinDays !== b.leadTimeMinDays ||
    a.leadTimeMaxDays !== b.leadTimeMaxDays
  )
    parts.push(
      `lead time ${leadTimeText(a.leadTimeMinDays ?? null, a.leadTimeMaxDays ?? null)} → ${leadTimeText(b.leadTimeMinDays ?? null, b.leadTimeMaxDays ?? null)}`,
    );
  return parts.join(", ") || null;
}

// Keys of the event data in the order they read best; ids are skipped because the sku
// or the shipment number next to them says the same for a human
const skippedKeys = new Set(["itemId", "shipmentId", "paymentId", "after"]);
const keyOrder = [
  "sku",
  "number",
  "quantity",
  "method",
  "fulfilment",
  "shipments",
  "totalMinor",
  "amountMinor",
  "unitPriceMinor",
  "goodsMinor",
  "points",
  "refundedBeforeMinor",
  "procurementStatus",
  "supplierReference",
  "replacesItemId",
  "itemIds",
  "from",
  "to",
  "before",
  "reopened",
  "replanned",
  "toOwnStock",
  "previousStatus",
  "customerAgreedVia",
  "reason",
  "note",
];

// One line of journal details from the event data
export function eventSummary(event: OrderEvent, order: AdminOrder): string {
  const data = event.data || {};
  const money = (value: unknown) =>
    typeof value === "number"
      ? formatMinor(value, order.currency)
      : String(value);
  const describe = (key: string, value: unknown): string | null => {
    switch (key) {
      case "sku":
        return String(value);
      case "number":
        return `shipment #${value}`;
      case "quantity":
        return `quantity ${value}`;
      case "method":
        return (
          deliveryMethodLabels[String(value)] ??
          paymentMethodLabels[value as PaymentMethod] ??
          String(value)
        );
      case "fulfilment":
        return value === "split" ? "in parts" : "all together";
      case "shipments":
        return `${value} ${value === 1 ? "shipment" : "shipments"}`;
      case "totalMinor":
        return `total ${money(value)}`;
      case "amountMinor":
        return money(value);
      case "unitPriceMinor":
        return `unit price ${money(value)}`;
      case "goodsMinor":
        return `goods ${money(value)}`;
      case "points":
        return `${value} points`;
      case "refundedBeforeMinor":
        return value ? `refunded before ${money(value)}` : null;
      case "procurementStatus":
        return statusLabel(String(value)).toLowerCase();
      case "supplierReference":
        return `supplier ref ${value}`;
      case "replacesItemId":
        return `replaces ${order.items.find((i) => i.id === value)?.sku ?? "a failed line"}`;
      case "itemIds":
        return Array.isArray(value)
          ? `${value.length} ${value.length === 1 ? "line" : "lines"}`
          : null;
      case "from":
        return `window ${formatDateTime(String(value))} – ${formatDateTime(typeof data.to === "string" ? data.to : null)}`;
      case "to":
        return null; // shown with "from"
      case "before":
        return termsText(value, data.after, order.currency);
      case "reopened":
        return value ? "order back to requested" : null;
      case "replanned":
        return value ? "re-planned after a refusal" : null;
      case "toOwnStock":
        return value ? "goods went to own stock" : null;
      case "previousStatus":
        return `was ${value}`;
      case "customerAgreedVia":
        return agreementText(value);
      case "reason":
        return `reason: ${value}`;
      case "note":
        return `note: ${value}`;
      default:
        return `${key}: ${typeof value === "object" ? JSON.stringify(value) : String(value)}`;
    }
  };
  const keys = [
    ...keyOrder.filter((key) => key in data),
    ...Object.keys(data).filter(
      (key) => !keyOrder.includes(key) && !skippedKeys.has(key),
    ),
  ];
  return keys
    .map((key) =>
      data[key] === null || data[key] === undefined || data[key] === ""
        ? null
        : describe(key, data[key]),
    )
    .filter(Boolean)
    .join(" · ");
}

// A failed order action as one readable sentence. attempted = the button label.
export function problemText(
  e: unknown,
  attempted: string,
  currency: string,
): string {
  if (!(e instanceof ApiError))
    return e instanceof TypeError
      ? "No answer from the server. Check that the API is running and try again."
      : errorText(e, `${attempted} failed`);
  const amount = (key: string) =>
    typeof e.body[key] === "number"
      ? formatMinor(e.body[key] as number, currency)
      : "—";
  switch (e.code) {
    case "action_not_allowed":
      return `“${attempted}” isn't allowed in the order's current state. The order was reloaded, so the buttons show what is possible now.`;
    case "overpayment":
      return `Not recorded: that is more than is still due. Still due: ${amount("amountDueMinor")}.`;
    case "refund_exceeds_paid":
      return `Not recorded: a refund can't be more than the net amount paid, ${amount("paidMinor")}.`;
    case "idempotency_conflict":
      return "Not recorded: this payment key was already used for a different entry. Check the ledger below; the next try gets a new key.";
    case "method_not_accepted":
      return `Not recorded: ${e.message}. Card and carrier cash on delivery work only once they are enabled on the server.`;
  }
  if (e.status === 401)
    return "The admin session ended. Reload the page and sign in again.";
  if (e.status === 403)
    return "The security token is no longer valid. Reload the page and try again.";
  if (e.status === 404)
    return `${e.message}. The order was reloaded in case it changed elsewhere.`;
  return e.message;
}
