"use client";
import { useState } from "react";
import {
  deliveryMethodLabels,
  formatPrice,
  paymentMethodLabels,
  paymentMethods,
  statusLabel,
  type AdminOrder,
  type PaymentKind,
  type PaymentMethod,
} from "./orders";
import {
  errorText,
  formatMinor,
  minorText,
  moneyHint,
  moneyPattern,
  parseMinor,
} from "./shared";

export type PaymentBody = {
  kind: PaymentKind;
  method: PaymentMethod;
  amountMinor: number;
  shipmentId: string | null;
  note: string | null;
};
// what the amount field starts with: what's due, or what's owed back
function suggested(order: AdminOrder, kind: PaymentKind): string {
  const amount =
    kind === "payment" ? order.amountDue.amount : order.refundDue.amount;
  return amount > 0 ? minorText(amount) : "";
}

// Without the settings every method is offered and the API refuses what it doesn't take;
// carrier cash on delivery is a way to get paid, never to pay back
function methodsFor(
  kind: PaymentKind,
  accepted: Record<PaymentKind, PaymentMethod[]> | null,
): PaymentMethod[] {
  if (accepted) return accepted[kind];
  return paymentMethods.filter(
    (m) => kind === "payment" || m !== "carrier_cod",
  );
}

export function PaymentForm({
  order,
  kinds,
  initialKind,
  accepted,
  busy,
  record,
  onCancel,
}: {
  order: AdminOrder;
  kinds: PaymentKind[];
  initialKind: PaymentKind;
  // from GET /api/admin/settings; null while it isn't loaded
  accepted: Record<PaymentKind, PaymentMethod[]> | null;
  busy: boolean;
  // the order panel picks the Idempotency-Key, so it outlives this form
  record: (body: PaymentBody) => Promise<void>;
  onCancel: () => void;
}) {
  const [kind, setKind] = useState<PaymentKind>(initialKind);
  const [picked, setPicked] = useState<PaymentMethod | "">("");
  const [amount, setAmount] = useState(() => suggested(order, initialKind));
  const [shipmentId, setShipmentId] = useState("");
  const [note, setNote] = useState("");
  const [problem, setProblem] = useState("");
  const methods = methodsFor(kind, accepted);
  // the settings can arrive after the form opened, so the method follows the list
  const method: PaymentMethod | "" =
    picked && methods.includes(picked) ? picked : (methods[0] ?? "");

  async function submit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setProblem("");
    let amountMinor: number | null;
    try {
      amountMinor = parseMinor(amount);
    } catch (err) {
      setProblem(errorText(err, "Check the amount"));
      return;
    }
    if (!amountMinor) {
      setProblem("Enter an amount above 0");
      return;
    }
    if (!method) {
      setProblem(
        `No method is accepted for a ${kind} right now. Check the API settings.`,
      );
      return;
    }
    const body: PaymentBody = {
      kind,
      method,
      amountMinor,
      shipmentId: shipmentId || null,
      note: note.trim() || null,
    };
    if (
      kind === "refund" &&
      !confirm(
        `Record a refund of ${formatMinor(amountMinor, order.currency)} (${paymentMethodLabels[method]}) on ${order.reference}? Ledger entries are never edited or deleted.`,
      )
    )
      return;
    await record(body);
  }

  return (
    <form className="order-form" onSubmit={submit}>
      <h4>{kind === "refund" ? "Record refund" : "Record payment"}</h4>
      <p className="variant-note">
        Still due {formatPrice(order.amountDue)} · paid{" "}
        {formatPrice(order.paid)}
        {order.refundDue.amount > 0 &&
          ` · to refund ${formatPrice(order.refundDue)}`}
        .
        {accepted
          ? " Only the methods the shop accepts are listed."
          : " Card and carrier cash on delivery work only once they are enabled on the server."}
      </p>
      <div className="admin-form-grid">
        <label className="admin-field">
          Kind
          <select
            value={kind}
            onChange={(e) => {
              const next = e.target.value as PaymentKind;
              setKind(next);
              setAmount(suggested(order, next));
            }}
          >
            {kinds.map((k) => (
              <option key={k} value={k}>
                {k === "refund" ? "Refund" : "Payment"}
              </option>
            ))}
          </select>
        </label>
        <label className="admin-field">
          Method
          <select
            value={method}
            onChange={(e) => setPicked(e.target.value as PaymentMethod)}
          >
            {!methods.length && <option value="">— none accepted —</option>}
            {methods.map((m) => (
              <option key={m} value={m}>
                {paymentMethodLabels[m]}
              </option>
            ))}
          </select>
        </label>
        <label className="admin-field">
          Amount · {order.currency === "CZK" ? "Kč" : order.currency}
          <input
            inputMode="decimal"
            pattern={moneyPattern}
            title={moneyHint}
            required
            value={amount}
            onChange={(e) => setAmount(e.target.value)}
          />
        </label>
        <label className="admin-field">
          Shipment · optional
          <select
            value={shipmentId}
            onChange={(e) => setShipmentId(e.target.value)}
          >
            <option value="">— not tied to a shipment —</option>
            {order.shipments.map((s) => (
              <option key={s.id} value={s.id}>
                #{s.number} · {deliveryMethodLabels[s.method] || s.method} ·{" "}
                {statusLabel(s.status)}
              </option>
            ))}
          </select>
        </label>
        <label className="admin-field wide">
          Note · optional
          <textarea
            rows={2}
            maxLength={500}
            value={note}
            onChange={(e) => setNote(e.target.value)}
          />
        </label>
      </div>
      {problem && (
        <p className="admin-error" role="alert">
          {problem}
        </p>
      )}
      <div className="order-form-actions">
        <button
          className={
            kind === "refund" ? "admin-primary danger" : "admin-primary"
          }
          disabled={busy}
        >
          {kind === "refund" ? "Record refund" : "Record payment"} ↗
        </button>
        <button type="button" className="admin-secondary" onClick={onCancel}>
          Close
        </button>
      </div>
    </form>
  );
}
