// D08.1–D08.2 evening installations: bookings beside the order, the evening windows and the works
export type BookingStatus = "planned" | "confirmed" | "done" | "cancelled";

export type AdminInstallation = {
  id: string;
  orderId: string;
  orderReference: string;
  customerName: string;
  status: BookingStatus;
  from: string;
  to: string;
  workFrom: string | null;
  workTo: string | null;
  durationMinutes: number;
  works: string[];
  workNames: string[];
  priceMinor: number | null;
  note: string | null;
  compatibilityNote: string | null;
  resultNote: string | null;
  cancelledReason: string | null;
  createdAt: string;
  createdBy: string;
  confirmedAt: string | null;
  completedAt: string | null;
  cancelledAt: string | null;
};

export type InstallationWorks = {
  works: { code: string; name: string; priceMinor: number | null }[];
  eveningStart: number;
  eveningEnd: number;
  maxPerEvening: number;
  travelMinutes: number;
};

export const bookingStatuses: BookingStatus[] = [
  "planned",
  "confirmed",
  "done",
  "cancelled",
];

export const bookingStatusLabels: Record<BookingStatus, string> = {
  planned: "Planned",
  confirmed: "Confirmed",
  done: "Done",
  cancelled: "Cancelled",
};
