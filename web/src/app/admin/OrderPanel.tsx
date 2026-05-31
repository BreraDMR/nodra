"use client";
import { useCallback, useEffect, useRef, useState } from "react";
import {
  ConfirmForm,
  OrderedForm,
  ReasonForm,
  ReplacementForm,
  ScheduleForm,
  TermsForm,
  type Submit,
} from "./OrderActionForms";
import { PaymentForm, type PaymentBody } from "./PaymentForm";
import {
  channelLabels,
  deliveryMethodLabels,
  eventLabel,
  eventSummary,
  formatPrice,
  itemActionLabels,
  leadTimeText,
  orderActionLabels,
  paymentMethodLabels,
  problemText,
  shipmentActionLabels,
  statusLabel,
  type AdminOrder,
  type AdminOrderItem,
  type AdminPayment,
  type AdminShipment,
  type ItemAction,
  type OrderAction,
  type PaymentKind,
  type ShipmentAction,
} from "./orders";
import {
  ApiError,
  availabilityLabels,
  errorText,
  formatCzk,
  formatDateTime,
  formatMinor,
  type Send,
} from "./shared";

// The one action form open right now; id is the order, line or shipment
type Open =
  | { scope: "order"; id: string; action: OrderAction }
  | { scope: "item"; id: string; action: ItemAction }
  | { scope: "shipment"; id: string; action: ShipmentAction };

// Is the open form's action still on the order's lists?
function stillAllowed(order: AdminOrder, open: Open): boolean {
  if (open.scope === "order") return order.actions.includes(open.action);
  if (open.scope === "item")
    return order.items.some(
      (i) => i.id === open.id && i.actions.includes(open.action),
    );
  return order.shipments.some(
    (s) => s.id === open.id && s.actions.includes(open.action),
  );
}

// actions that change something for good get a red button
const dangerous = new Set(["cancel", "return", "refuse", "record_refund"]);

export function Chip({ value }: { value: string }) {
  return <span className={`status ${value}`}>{statusLabel(value)}</span>;
}

// 32 random hex chars; getRandomValues works on plain http too, randomUUID doesn't
function newKey(): string {
  const bytes = crypto.getRandomValues(new Uint8Array(16));
  return (
    "pay-" + Array.from(bytes, (b) => b.toString(16).padStart(2, "0")).join("")
  );
}

function ActionBar<A extends string>({
  actions,
  labels,
  open,
  busy,
  onPick,
}: {
  actions: A[];
  labels: Record<A, string>;
  open: string | null;
  busy: boolean;
  onPick: (action: A) => void;
}) {
  if (!actions.length) return null;
  return (
    <div className="order-action-bar">
      {actions.map((action) => (
        <button
          key={action}
          type="button"
          disabled={busy}
          aria-pressed={open === action}
          className={`${dangerous.has(action) ? "danger" : ""} ${open === action ? "active" : ""}`}
          onClick={() => onPick(action)}
        >
          {labels[action] || action}
        </button>
      ))}
    </div>
  );
}

function Row({
  label,
  children,
}: {
  label: string;
  children: React.ReactNode;
}) {
  return (
    <>
      <dt>{label}</dt>
      <dd>{children}</dd>
    </>
  );
}

export function OrderPanel({
  orderId,
  send,
  onClose,
  onChanged,
}: {
  orderId: string;
  send: Send;
  onClose: () => void;
  // the list and the dashboard follow the order's new status
  onChanged: () => void;
}) {
  const [order, setOrder] = useState<AdminOrder | null>(null);
  const [loadError, setLoadError] = useState("");
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [busy, setBusy] = useState(false);
  const [open, setOpen] = useState<Open | null>(null);
  // One Idempotency-Key per ledger entry. A retry of exactly the same entry after a
  // lost answer reuses it, so the server hands back the first entry instead of writing
  // a second one; any change to the form makes it a new entry with a new key.
  const pendingPayment = useRef<{ body: string; key: string } | null>(null);

  const fetchOrder = useCallback(
    () => send<AdminOrder>(`/api/admin/orders/${orderId}`),
    [send, orderId],
  );
  useEffect(() => {
    let live = true;
    fetchOrder()
      .then((result) => live && setOrder(result))
      .catch((e) => live && setLoadError(errorText(e, "Could not load order")));
    return () => {
      live = false;
    };
  }, [fetchOrder]);

  async function reload() {
    try {
      const fresh = await fetchOrder();
      setOrder(fresh);
      // a form for something no longer allowed would only fail again
      setOpen((current) =>
        current && stillAllowed(fresh, current) ? current : null,
      );
    } catch {
      // the action error above says enough
    }
  }

  function done(updated: AdminOrder, text: string) {
    setOrder(updated);
    setOpen(null);
    setNotice(text);
    onChanged();
  }

  async function failed(e: unknown, label: string) {
    setError(problemText(e, label, order?.currency || "CZK"));
    // the state moved under us: show what is allowed now
    if (e instanceof ApiError && (e.status === 409 || e.status === 404))
      await reload();
  }

  // Every action answers with the whole order, so the screen just re-renders from it
  const run: Submit = async (path, body, label) => {
    setBusy(true);
    setError("");
    setNotice("");
    try {
      const updated = await send<AdminOrder>(
        `/api/admin/orders/${orderId}${path}`,
        "POST",
        body,
      );
      done(updated, `${label}: done.`);
      return true;
    } catch (e) {
      await failed(e, label);
      return false;
    } finally {
      setBusy(false);
    }
  };

  async function recordPayment(body: PaymentBody) {
    const text = JSON.stringify(body);
    if (pendingPayment.current?.body !== text)
      pendingPayment.current = { body: text, key: newKey() };
    const label = body.kind === "refund" ? "Record refund" : "Record payment";
    setBusy(true);
    setError("");
    setNotice("");
    try {
      const result = await send<{ payment: AdminPayment; order: AdminOrder }>(
        `/api/admin/orders/${orderId}/payments`,
        "POST",
        body,
        { "Idempotency-Key": pendingPayment.current.key },
      );
      pendingPayment.current = null;
      done(
        result.order,
        `${body.kind === "refund" ? "Refund" : "Payment"} of ${formatPrice(result.payment.amount)} recorded.`,
      );
    } catch (e) {
      // no answer (or a proxy error): the entry may be written, so keep the key for a retry
      const unknown = !(e instanceof ApiError) || e.status >= 500;
      if (unknown) {
        setError(
          `${errorText(e, "No answer from the server")}. It's unclear whether the entry was recorded. Press ${label} again without changing anything: the same key makes the retry safe.`,
        );
      } else {
        pendingPayment.current = null;
        await failed(e, label);
      }
    } finally {
      setBusy(false);
    }
  }

  // Steps without a form: one click, the ones that can't be undone ask first
  async function direct(path: string, label: string, question?: string) {
    if (question && !confirm(question)) return;
    await run(path, undefined, label);
  }

  function pickOrderAction(action: OrderAction) {
    setOpen(
      open?.scope === "order" && open.action === action
        ? null
        : { scope: "order", id: orderId, action },
    );
  }
  function pickItemAction(item: AdminOrderItem, action: ItemAction) {
    if (action === "mark_received") {
      void direct(`/items/${item.id}/received`, "Mark received");
      return;
    }
    setOpen(
      open?.scope === "item" && open.id === item.id && open.action === action
        ? null
        : { scope: "item", id: item.id, action },
    );
  }
  function pickShipmentAction(shipment: AdminShipment, action: ShipmentAction) {
    if (action === "hand_over") {
      void direct(
        `/shipments/${shipment.id}/hand-over`,
        "Hand over",
        `Hand shipment #${shipment.number} over to the customer? This can't be undone; once every line is delivered and paid the order completes.`,
      );
      return;
    }
    setOpen(
      open?.scope === "shipment" &&
        open.id === shipment.id &&
        open.action === action
        ? null
        : { scope: "shipment", id: shipment.id, action },
    );
  }

  const close = () => setOpen(null);
  const formProps = { busy, onSubmit: run, onCancel: close };

  function orderForm(o: AdminOrder) {
    if (open?.scope !== "order") return null;
    if (open.action === "confirm")
      return <ConfirmForm order={o} {...formProps} />;
    if (open.action === "cancel")
      return (
        <ReasonForm
          key="cancel"
          title="Cancel order"
          path="/cancel"
          hint="Reserved own stock goes back, received goods become own stock. Money already taken stays in the ledger as refund due."
          confirmText={`Cancel the whole order ${o.reference}? This can't be undone.`}
          {...formProps}
        />
      );
    const kinds: PaymentKind[] = [
      ...(o.actions.includes("record_payment") ? ["payment" as const] : []),
      ...(o.actions.includes("record_refund") ? ["refund" as const] : []),
    ];
    return (
      <PaymentForm
        key={open.action}
        order={o}
        kinds={kinds}
        initialKind={open.action === "record_refund" ? "refund" : "payment"}
        busy={busy}
        record={recordPayment}
        onCancel={close}
      />
    );
  }

  function itemForm(o: AdminOrder, item: AdminOrderItem) {
    if (open?.scope !== "item" || open.id !== item.id) return null;
    const reason = (
      title: string,
      path: string,
      extra: { hint?: string; confirmText?: string } = {},
    ) => (
      <ReasonForm
        key={open.action}
        title={title}
        path={`/items/${item.id}/${path}`}
        {...extra}
        {...formProps}
      />
    );
    switch (open.action) {
      case "change_terms":
        return <TermsForm order={o} item={item} {...formProps} />;
      case "mark_ordered":
        return <OrderedForm item={item} {...formProps} />;
      case "mark_failed":
        return reason("Mark failed", "failed", {
          hint: "The supplier can't deliver. Then cancel the line or replace it; the order can't be confirmed while a failed line is active.",
        });
      case "cancel":
        return reason("Cancel line", "cancel", {
          hint: "Reserved own stock goes back, received goods become own stock. A shipment left empty is cancelled with its fee.",
          confirmText: `Cancel ${item.quantity} × ${item.sku} on ${o.reference}? This can't be undone.`,
        });
      case "replace":
        return (
          <ReplacementForm order={o} item={item} send={send} {...formProps} />
        );
      case "return":
        return reason("Take back", "return", {
          hint: "The 14-day withdrawal: the line becomes returned and its goods go to own stock. Record the refund separately.",
          confirmText: `Take back ${item.quantity} × ${item.sku} from ${o.reference}? This can't be undone.`,
        });
      default:
        return null;
    }
  }

  function shipmentForm(shipment: AdminShipment) {
    if (open?.scope !== "shipment" || open.id !== shipment.id) return null;
    if (open.action === "schedule")
      return <ScheduleForm shipment={shipment} {...formProps} />;
    if (open.action === "refuse")
      return (
        <ReasonForm
          title="Customer refused"
          path={`/shipments/${shipment.id}/refused`}
          hint="Its goods become own stock. Then cancel the order or schedule the shipment again."
          confirmText={`Record that the customer refused shipment #${shipment.number}? This can't be undone.`}
          {...formProps}
        />
      );
    return null;
  }

  return (
    <div
      className="admin-modal-backdrop"
      onMouseDown={(e) => {
        if (e.target === e.currentTarget) onClose();
      }}
    >
      <div className="admin-modal order-modal">
        <div className="modal-header">
          <div>
            <p className="eyebrow">FULFILMENT / ORDER</p>
            <h2>{order?.reference || "Order"}</h2>
          </div>
          <button onClick={onClose} aria-label="Close">
            ×
          </button>
        </div>
        {(notice || error) && (
          <div className="order-messages">
            {notice && (
              <div className="admin-success" role="status">
                ✓ {notice}
                <button type="button" onClick={() => setNotice("")}>
                  ×
                </button>
              </div>
            )}
            {error && (
              <div className="admin-error" role="alert">
                {error}
                <button type="button" onClick={() => setError("")}>
                  ×
                </button>
              </div>
            )}
          </div>
        )}
        {!order &&
          (loadError ? (
            <p className="admin-error" role="alert">
              {loadError}
            </p>
          ) : (
            <p className="admin-empty">Loading order…</p>
          ))}
        {order && (
          <div className="order-panel">
            <div className="order-chips">
              <Chip value={order.status} />
              <Chip value={order.paymentStatus} />
              <span>
                {order.fulfilment === "split" ? "In parts" : "All together"} ·{" "}
                {order.locale.toUpperCase()} · placed{" "}
                {formatDateTime(order.createdAt)}
              </span>
              <button
                type="button"
                className="admin-link"
                disabled={busy}
                onClick={() => {
                  setError("");
                  setNotice("");
                  void reload();
                }}
              >
                ↻ Reload
              </button>
            </div>
            <Totals order={order} />
            <ActionBar
              actions={order.actions}
              labels={orderActionLabels}
              open={open?.scope === "order" ? open.action : null}
              busy={busy}
              onPick={pickOrderAction}
            />
            {orderForm(order)}
            {!order.actions.length && (
              <p className="variant-note">
                Nothing left to do on the order as a whole.
              </p>
            )}

            <h3>Customer</h3>
            <Customer order={order} />

            <h3>Lines</h3>
            {order.items.map((item) => (
              <LineCard key={item.id} order={order} item={item}>
                <ActionBar
                  actions={item.actions}
                  labels={itemActionLabels}
                  open={
                    open?.scope === "item" && open.id === item.id
                      ? open.action
                      : null
                  }
                  busy={busy}
                  onPick={(action) => pickItemAction(item, action)}
                />
                {itemForm(order, item)}
              </LineCard>
            ))}

            <h3>Shipments</h3>
            {order.shipments.map((shipment) => (
              <ShipmentCard key={shipment.id} order={order} shipment={shipment}>
                <ActionBar
                  actions={shipment.actions}
                  labels={shipmentActionLabels}
                  open={
                    open?.scope === "shipment" && open.id === shipment.id
                      ? open.action
                      : null
                  }
                  busy={busy}
                  onPick={(action) => pickShipmentAction(shipment, action)}
                />
                {shipmentForm(shipment)}
              </ShipmentCard>
            ))}

            <h3>Payments</h3>
            <Payments order={order} />

            <h3>Journal</h3>
            <Journal order={order} />
          </div>
        )}
      </div>
    </div>
  );
}

const skuOf = (order: AdminOrder, id: string) =>
  order.items.find((i) => i.id === id)?.sku;
const shipmentNumber = (order: AdminOrder, id: string | null) =>
  order.shipments.find((s) => s.id === id)?.number ?? "?";

function Totals({ order }: { order: AdminOrder }) {
  return (
    <div className="order-totals">
      <div>
        <span>Goods</span>
        <strong>{formatPrice(order.subtotal)}</strong>
      </div>
      <div>
        <span>Shipping</span>
        <strong>{formatPrice(order.shipping)}</strong>
      </div>
      <div>
        <span>Total</span>
        <strong>{formatPrice(order.total)}</strong>
      </div>
      <div>
        <span>Paid</span>
        <strong>{formatPrice(order.paid)}</strong>
      </div>
      <div className={order.amountDue.amount > 0 ? "due" : ""}>
        <span>Still due</span>
        <strong>{formatPrice(order.amountDue)}</strong>
      </div>
      <div className={order.refundDue.amount > 0 ? "refund" : ""}>
        <span>Refund due</span>
        <strong>{formatPrice(order.refundDue)}</strong>
      </div>
    </div>
  );
}

function Customer({ order }: { order: AdminOrder }) {
  const { customer, consents } = order;
  const place = [
    customer.address,
    [customer.postalCode, customer.city].filter(Boolean).join(" "),
    customer.district,
    customer.country,
  ].filter(Boolean);
  return (
    <dl className="pricing-rows">
      <Row label="Name">{customer.name}</Row>
      <Row label="Email">
        <a href={`mailto:${customer.email}`}>{customer.email}</a>
      </Row>
      <Row label="Phone">
        {customer.phone ? (
          <a href={`tel:${customer.phone.replace(/\s/g, "")}`}>
            {customer.phone}
          </a>
        ) : (
          "—"
        )}
      </Row>
      <Row label="Preferred channel">
        {customer.contactChannel
          ? channelLabels[customer.contactChannel] || customer.contactChannel
          : "—"}
      </Row>
      <Row label="Address">{place.length ? place.join(", ") : "—"}</Row>
      <Row label="Delivery note">{customer.deliveryNote || "—"}</Row>
      <Row label="Account">
        {customer.accountId ? "Signed-in customer" : "Guest"}
      </Row>
      <Row label="Privacy consent">
        {consents.privacyConsentedAt ? (
          <>
            {formatDateTime(consents.privacyConsentedAt)}
            <small>text version {consents.privacyTextVersion || "—"}</small>
          </>
        ) : (
          "— not recorded (placed before D04)"
        )}
      </Row>
      <Row label="Marketing consent">
        {consents.marketing
          ? `Yes · ${formatDateTime(consents.marketingConsentedAt)}`
          : "No"}
      </Row>
    </dl>
  );
}

function LineCard({
  order,
  item,
  children,
}: {
  order: AdminOrder;
  item: AdminOrderItem;
  children: React.ReactNode;
}) {
  const money = (minor: number) => formatMinor(minor, order.currency);
  return (
    <div className={`order-line${item.state === "active" ? "" : " inactive"}`}>
      <div className="order-line-head">
        <div>
          <strong>
            {item.name} / {item.variant}
          </strong>
          <small>
            {item.quantity} × {money(item.unitPrice)} · {item.sku}
          </small>
        </div>
        <strong>{money(item.lineTotal)}</strong>
      </div>
      <div className="order-line-chips">
        {item.state !== "active" && <Chip value={item.state} />}
        <Chip value={item.procurementStatus} />
        <span>Shipment #{shipmentNumber(order, item.shipmentId)}</span>
        {item.replacesItemId && (
          <span>
            replaces {skuOf(order, item.replacesItemId) ?? "a failed line"}
          </span>
        )}
      </div>
      {/* admin-only: supplier and cost never reach the customer */}
      <dl className="order-snapshot">
        <dt>Supplier ref</dt>
        <dd>{item.supplierReference || "—"}</dd>
        <dt>Lead time</dt>
        <dd>{leadTimeText(item.leadTimeMinDays, item.leadTimeMaxDays)}</dd>
        <dt>At checkout</dt>
        <dd>
          {item.availabilityStatus
            ? availabilityLabels[item.availabilityStatus] ||
              item.availabilityStatus
            : "—"}
        </dd>
        <dt>Unit cost</dt>
        <dd>
          {item.unitCostCzkMinor === null
            ? "—"
            : formatCzk(item.unitCostCzkMinor)}
        </dd>
        <dt>Offer</dt>
        <dd title={item.supplierOfferId || undefined}>
          {item.supplierOfferId ? `${item.supplierOfferId.slice(0, 8)}…` : "—"}
        </dd>
      </dl>
      {children}
    </div>
  );
}

function ShipmentCard({
  order,
  shipment,
  children,
}: {
  order: AdminOrder;
  shipment: AdminShipment;
  children: React.ReactNode;
}) {
  const open =
    shipment.status !== "cancelled" && shipment.status !== "handed_over";
  return (
    <div
      className={`order-line${shipment.status === "cancelled" ? " inactive" : ""}`}
    >
      <div className="order-line-head">
        <div>
          <strong>
            #{shipment.number} ·{" "}
            {deliveryMethodLabels[shipment.method] || shipment.method}
          </strong>
          <small>
            {shipment.itemIds.map((id) => skuOf(order, id) ?? id).join(", ") ||
              "no lines"}
          </small>
        </div>
        <strong>{formatPrice(shipment.fee)}</strong>
      </div>
      <div className="order-line-chips">
        <Chip value={shipment.status} />
        {open && (
          <span className={shipment.ready ? "ready" : ""}>
            {shipment.ready
              ? "Ready: every line in hand"
              : "Not ready: waiting for goods"}
          </span>
        )}
      </div>
      <dl className="order-snapshot">
        <dt>Lead time</dt>
        <dd>
          {leadTimeText(shipment.leadTimeMinDays, shipment.leadTimeMaxDays)}
        </dd>
        <dt>Window</dt>
        <dd>
          {shipment.scheduledFrom
            ? `${formatDateTime(shipment.scheduledFrom)} – ${formatDateTime(shipment.scheduledTo)}`
            : "—"}
        </dd>
        <dt>Handed over</dt>
        <dd>{formatDateTime(shipment.handedOverAt)}</dd>
      </dl>
      {children}
    </div>
  );
}

function Payments({ order }: { order: AdminOrder }) {
  if (!order.payments.length)
    return <p className="variant-note">No money recorded yet.</p>;
  return (
    <div className="admin-table-wrap">
      <table className="admin-table compact-table">
        <thead>
          <tr>
            <th>WHEN</th>
            <th>KIND</th>
            <th>AMOUNT</th>
            <th>SHIPMENT</th>
            <th>BY / NOTE</th>
          </tr>
        </thead>
        <tbody>
          {order.payments.map((p) => (
            <tr key={p.id}>
              <td>{formatDateTime(p.recordedAt)}</td>
              <td>
                <b>{p.kind === "refund" ? "Refund" : "Payment"}</b>
                <small>{paymentMethodLabels[p.method] || p.method}</small>
              </td>
              <td className={p.kind === "refund" ? "order-refund" : ""}>
                {p.kind === "refund" ? "−" : ""}
                {formatPrice(p.amount)}
              </td>
              <td>
                {p.shipmentId ? `#${shipmentNumber(order, p.shipmentId)}` : "—"}
              </td>
              <td>
                {p.recordedBy}
                {p.note && <small>{p.note}</small>}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function Journal({ order }: { order: AdminOrder }) {
  return (
    <ol className="order-journal">
      {order.events.map((event) => {
        const details = eventSummary(event, order);
        return (
          <li key={event.id}>
            <small>
              {formatDateTime(event.createdAt)} · {event.actor}
            </small>
            <strong>{eventLabel(event.type)}</strong>
            {details && <p>{details}</p>}
          </li>
        );
      })}
    </ol>
  );
}
