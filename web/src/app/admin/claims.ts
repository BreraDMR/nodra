// D08.4 after-sale claims: returns and warranty cases registered beside their order
export type ClaimKind = "return" | "warranty";
export type ClaimStatus =
  "open" | "waiting" | "accepted" | "rejected" | "resolved";
export type ClaimResolution = "refund" | "replacement" | "repair";

export type AdminClaim = {
  id: string;
  number: string;
  orderId: string;
  orderReference: string;
  customerName: string;
  itemId: string | null;
  productName: string | null;
  variantLabel: string | null;
  sku: string | null;
  kind: ClaimKind;
  status: ClaimStatus;
  note: string | null;
  refundAmountMinor: number | null;
  resolution: ClaimResolution | null;
  resolutionNote: string | null;
  openedAt: string;
  openedBy: string;
  contactedOn: string;
  handoverDate: string;
  windowEnd: string;
  onTime: boolean;
  dueAt: string | null;
  overdue: boolean;
  resolvedAt: string | null;
};

export const claimStatuses: ClaimStatus[] = [
  "open",
  "waiting",
  "accepted",
  "rejected",
  "resolved",
];
export const claimKinds: ClaimKind[] = ["return", "warranty"];
export const claimResolutions: ClaimResolution[] = [
  "refund",
  "replacement",
  "repair",
];

export const claimKindLabels: Record<ClaimKind, string> = {
  return: "Return (14 days)",
  warranty: "Warranty (24 months)",
};

// Prague day the customer lodged the case, for the register form's default
export const todayInPrague = (): string =>
  new Intl.DateTimeFormat("sv-SE", { timeZone: "Europe/Prague" }).format(
    new Date(),
  );

export const claimStatusLabels: Record<ClaimStatus, string> = {
  open: "Open",
  waiting: "Waiting for the customer",
  accepted: "Accepted",
  rejected: "Rejected",
  resolved: "Resolved",
};

export const claimResolutionLabels: Record<ClaimResolution, string> = {
  refund: "Refund",
  replacement: "Replacement",
  repair: "Repair",
};
