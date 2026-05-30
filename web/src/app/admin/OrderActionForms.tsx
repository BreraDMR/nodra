"use client";
import { useState } from "react";
import {
  agreementChannels,
  channelLabels,
  deliveryMethodLabels,
  leadTimeText,
  type AdminOrder,
  type AdminOrderItem,
  type AdminShipment,
  type Agreement,
  type AgreementChannel,
} from "./orders";
import {
  errorText,
  formatCzk,
  formatMinor,
  minorText,
  moneyHint,
  moneyPattern,
  parseApiDate,
  parseMinor,
  toLocalInput,
  type Paged,
  type Send,
} from "./shared";

// Posts to /api/admin/orders/{id}{path}; true when the order came back updated
export type Submit = (
  path: string,
  body: unknown,
  label: string,
) => Promise<boolean>;
type FormProps = { busy: boolean; onSubmit: Submit; onCancel: () => void };

type AgreementDraft = { channel: AgreementChannel | ""; note: string };

// Start from the channel the customer picked at checkout
function draftFor(order: AdminOrder, optional = false): AgreementDraft {
  return {
    channel: optional ? "" : order.customer.contactChannel || "whatsapp",
    note: "",
  };
}

function agreementBody(draft: AgreementDraft): Agreement | null {
  if (!draft.channel) return null;
  if (!draft.note.trim())
    throw Error("Write down what the customer agreed to in the note");
  return { channel: draft.channel, note: draft.note.trim() };
}

function AgreementFields({
  value,
  onChange,
  optional = false,
}: {
  value: AgreementDraft;
  onChange: (value: AgreementDraft) => void;
  optional?: boolean;
}) {
  return (
    <>
      <label className="admin-field">
        Customer agreed via
        <select
          value={value.channel}
          onChange={(e) =>
            onChange({
              ...value,
              channel: e.target.value as AgreementChannel | "",
            })
          }
        >
          {optional && <option value="">— not agreed yet —</option>}
          {agreementChannels.map((channel) => (
            <option key={channel} value={channel}>
              {channelLabels[channel]}
            </option>
          ))}
        </select>
      </label>
      <label className="admin-field wide">
        Agreement note
        <textarea
          rows={2}
          maxLength={500}
          required={!optional || value.channel !== ""}
          disabled={optional && value.channel === ""}
          placeholder="What was agreed, e.g. 1 290 Kč, handover Thursday after 17:00"
          value={value.note}
          onChange={(e) => onChange({ ...value, note: e.target.value })}
        />
      </label>
    </>
  );
}

function FormFooter({
  busy,
  submit,
  onCancel,
  problem,
  danger = false,
}: {
  busy: boolean;
  submit: string;
  onCancel: () => void;
  problem: string;
  danger?: boolean;
}) {
  return (
    <>
      {problem && (
        <p className="admin-error" role="alert">
          {problem}
        </p>
      )}
      <div className="order-form-actions">
        <button
          className={danger ? "admin-primary danger" : "admin-primary"}
          disabled={busy}
        >
          {submit} ↗
        </button>
        <button type="button" className="admin-secondary" onClick={onCancel}>
          Close
        </button>
      </div>
    </>
  );
}

export function ConfirmForm({
  order,
  busy,
  onSubmit,
  onCancel,
}: FormProps & { order: AdminOrder }) {
  const [agreement, setAgreement] = useState(() => draftFor(order));
  const [problem, setProblem] = useState("");
  return (
    <form
      className="order-form"
      onSubmit={async (e) => {
        e.preventDefault();
        setProblem("");
        try {
          await onSubmit(
            "/confirm",
            { customerAgreedVia: agreementBody(agreement) },
            "Confirm order",
          );
        } catch (err) {
          setProblem(errorText(err, "Check the agreement"));
        }
      }}
    >
      <h4>Confirm order</h4>
      <p className="variant-note">
        The customer agreed to the price and the date. How and what goes to the
        journal.
      </p>
      <div className="admin-form-grid">
        <AgreementFields value={agreement} onChange={setAgreement} />
      </div>
      <FormFooter
        busy={busy}
        submit="Confirm order"
        onCancel={onCancel}
        problem={problem}
      />
    </form>
  );
}

// Cancel order or line, failed, return, refused: all take one reason
export function ReasonForm({
  title,
  path,
  hint,
  confirmText,
  busy,
  onSubmit,
  onCancel,
}: FormProps & {
  title: string;
  path: string;
  hint?: string;
  // asked before sending, for the steps that can't be undone
  confirmText?: string;
}) {
  const [reason, setReason] = useState("");
  const [problem, setProblem] = useState("");
  return (
    <form
      className="order-form"
      onSubmit={async (e) => {
        e.preventDefault();
        setProblem("");
        if (!reason.trim()) {
          setProblem("Enter a reason");
          return;
        }
        if (confirmText && !confirm(confirmText)) return;
        await onSubmit(path, { reason: reason.trim() }, title);
      }}
    >
      <h4>{title}</h4>
      {hint && <p className="variant-note">{hint}</p>}
      <label className="admin-field">
        Reason
        <textarea
          rows={2}
          maxLength={500}
          required
          value={reason}
          onChange={(e) => setReason(e.target.value)}
        />
      </label>
      <FormFooter
        busy={busy}
        submit={title}
        onCancel={onCancel}
        problem={problem}
        danger={Boolean(confirmText)}
      />
    </form>
  );
}

export function TermsForm({
  order,
  item,
  busy,
  onSubmit,
  onCancel,
}: FormProps & { order: AdminOrder; item: AdminOrderItem }) {
  const days = (n: number | null) => (n === null ? "" : String(n));
  const [price, setPrice] = useState(minorText(item.unitPrice));
  const [min, setMin] = useState(days(item.leadTimeMinDays));
  const [max, setMax] = useState(days(item.leadTimeMaxDays));
  const [agreement, setAgreement] = useState(() => draftFor(order, true));
  const [problem, setProblem] = useState("");

  function body() {
    const unitPriceMinor = parseMinor(price);
    const priceChanged =
      unitPriceMinor !== null && unitPriceMinor !== item.unitPrice;
    if ((min.trim() === "") !== (max.trim() === ""))
      throw Error("Enter both the minimum and the maximum lead time");
    const lead =
      min.trim() === "" ? null : { min: Number(min), max: Number(max) };
    if (lead) {
      const ok = (n: number) => Number.isInteger(n) && n >= 0 && n <= 365;
      if (!ok(lead.min) || !ok(lead.max))
        throw Error("Lead time is whole days from 0 to 365");
      if (lead.min > lead.max)
        throw Error("The minimum lead time can't be above the maximum");
    }
    const leadChanged =
      lead !== null &&
      (lead.min !== item.leadTimeMinDays || lead.max !== item.leadTimeMaxDays);
    if (!priceChanged && !leadChanged)
      throw Error("Nothing changed: edit the unit price or the lead time");
    // only what changed goes out; lead time always as a pair
    return {
      ...(priceChanged ? { unitPriceMinor } : {}),
      ...(leadChanged
        ? { leadTimeMinDays: lead.min, leadTimeMaxDays: lead.max }
        : {}),
      customerAgreedVia: agreementBody(agreement),
    };
  }

  return (
    <form
      className="order-form"
      onSubmit={async (e) => {
        e.preventDefault();
        setProblem("");
        let payload;
        try {
          payload = body();
        } catch (err) {
          setProblem(errorText(err, "Check the new terms"));
          return;
        }
        await onSubmit(`/items/${item.id}/terms`, payload, "Change terms");
      }}
    >
      <h4>Change terms · {item.sku}</h4>
      <p className="variant-note">
        Now {formatMinor(item.unitPrice, order.currency)} per unit, lead time{" "}
        {leadTimeText(item.leadTimeMinDays, item.leadTimeMaxDays)}.
        {order.status === "confirmed" &&
          " The order goes back to requested until the customer agrees again (Confirm order)."}{" "}
        Shipping fees never go up.
      </p>
      <div className="admin-form-grid">
        <label className="admin-field">
          Unit price · Kč
          <input
            inputMode="decimal"
            pattern={moneyPattern}
            title={moneyHint}
            value={price}
            onChange={(e) => setPrice(e.target.value)}
          />
        </label>
        <div className="order-form-pair">
          <label className="admin-field">
            Lead min · days
            <input
              type="number"
              min={0}
              max={365}
              value={min}
              onChange={(e) => setMin(e.target.value)}
            />
          </label>
          <label className="admin-field">
            Lead max · days
            <input
              type="number"
              min={0}
              max={365}
              value={max}
              onChange={(e) => setMax(e.target.value)}
            />
          </label>
        </div>
        <AgreementFields value={agreement} onChange={setAgreement} optional />
      </div>
      <FormFooter
        busy={busy}
        submit="Change terms"
        onCancel={onCancel}
        problem={problem}
      />
    </form>
  );
}

export function OrderedForm({
  item,
  busy,
  onSubmit,
  onCancel,
}: FormProps & { item: AdminOrderItem }) {
  const [reference, setReference] = useState("");
  const [problem, setProblem] = useState("");
  return (
    <form
      className="order-form"
      onSubmit={async (e) => {
        e.preventDefault();
        setProblem("");
        if (!reference.trim()) {
          setProblem("Enter the supplier order number or link");
          return;
        }
        await onSubmit(
          `/items/${item.id}/ordered`,
          { supplierReference: reference.trim() },
          "Mark ordered",
        );
      }}
    >
      <h4>Mark ordered · {item.sku}</h4>
      <label className="admin-field">
        Supplier order number or link · admin only
        <input
          maxLength={120}
          required
          value={reference}
          onChange={(e) => setReference(e.target.value)}
        />
      </label>
      <FormFooter
        busy={busy}
        submit="Mark ordered"
        onCancel={onCancel}
        problem={problem}
      />
    </form>
  );
}

type ProductHit = {
  id: string;
  name: string;
  status: string;
  variants: {
    id: string;
    sku: string;
    label: Record<string, string>;
    priceCzk: number;
    stock: number;
    active: boolean;
  }[];
};

export function ReplacementForm({
  order,
  item,
  send,
  busy,
  onSubmit,
  onCancel,
}: FormProps & { order: AdminOrder; item: AdminOrderItem; send: Send }) {
  const [search, setSearch] = useState("");
  const [hits, setHits] = useState<ProductHit[] | null>(null);
  const [searching, setSearching] = useState(false);
  const [variantId, setVariantId] = useState("");
  const [quantity, setQuantity] = useState("");
  const [price, setPrice] = useState("");
  const [agreement, setAgreement] = useState(() => draftFor(order));
  const [problem, setProblem] = useState("");
  const chosen = hits
    ?.flatMap((p) => p.variants)
    .find((v) => v.id === variantId);

  // the product search of the Products tab, first page only
  async function find() {
    setSearching(true);
    setProblem("");
    try {
      const query = new URLSearchParams({ page: "1" });
      if (search.trim()) query.set("q", search.trim());
      const page = await send<Paged<ProductHit>>(
        `/api/admin/products?${query}`,
      );
      setHits(page.items);
      setVariantId("");
      if (!page.items.length) setProblem("No products match that search");
    } catch (err) {
      setProblem(errorText(err, "Search failed"));
    } finally {
      setSearching(false);
    }
  }

  return (
    <form
      className="order-form"
      onSubmit={async (e) => {
        e.preventDefault();
        setProblem("");
        let payload;
        try {
          if (!variantId) throw Error("Pick the replacement variant");
          const qty = quantity.trim() === "" ? null : Number(quantity);
          if (qty !== null && (!Number.isInteger(qty) || qty < 1 || qty > 10))
            throw Error("Quantity is a whole number from 1 to 10");
          payload = {
            variantId,
            customerAgreedVia: agreementBody(agreement),
            quantity: qty,
            unitPriceMinor: parseMinor(price),
          };
        } catch (err) {
          setProblem(errorText(err, "Check the replacement"));
          return;
        }
        await onSubmit(`/items/${item.id}/replacement`, payload, "Replace");
      }}
    >
      <h4>Replace · {item.sku}</h4>
      <p className="variant-note">
        Only with the customer&apos;s agreement. The failed line is cancelled
        and the new one joins the same shipment.
      </p>
      <div className="order-search">
        <input
          type="search"
          value={search}
          maxLength={80}
          placeholder="Search product name or slug"
          aria-label="Search replacement product"
          onChange={(e) => setSearch(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === "Enter") {
              e.preventDefault();
              void find();
            }
          }}
        />
        <button
          type="button"
          className="admin-secondary"
          disabled={searching}
          onClick={() => void find()}
        >
          {searching ? "Searching…" : "Search ↗"}
        </button>
      </div>
      <div className="admin-form-grid">
        <label className="admin-field wide">
          Replacement variant
          <select
            value={variantId}
            required
            disabled={!hits?.length}
            onChange={(e) => setVariantId(e.target.value)}
          >
            <option value="">
              {hits ? "— pick a variant —" : "— search first —"}
            </option>
            {hits?.map((product) => (
              <optgroup
                key={product.id}
                label={`${product.name}${product.status === "published" ? "" : ` · ${product.status}`}`}
              >
                {product.variants.map((v) => (
                  <option key={v.id} value={v.id} disabled={!v.active}>
                    {v.label.en} · {v.sku} · {formatCzk(v.priceCzk)} · own stock{" "}
                    {v.stock}
                    {v.active ? "" : " · inactive"}
                  </option>
                ))}
              </optgroup>
            ))}
          </select>
        </label>
        <label className="admin-field">
          Quantity · optional
          <input
            type="number"
            min={1}
            max={10}
            placeholder={String(item.quantity)}
            value={quantity}
            onChange={(e) => setQuantity(e.target.value)}
          />
        </label>
        <label className="admin-field">
          Unit price · Kč · optional
          <input
            inputMode="decimal"
            pattern={moneyPattern}
            title={moneyHint}
            placeholder={chosen ? minorText(chosen.priceCzk) : "variant price"}
            value={price}
            onChange={(e) => setPrice(e.target.value)}
          />
        </label>
        <AgreementFields value={agreement} onChange={setAgreement} />
      </div>
      <FormFooter
        busy={busy}
        submit="Replace"
        onCancel={onCancel}
        problem={problem}
      />
    </form>
  );
}

export function ScheduleForm({
  shipment,
  busy,
  onSubmit,
  onCancel,
}: FormProps & { shipment: AdminShipment }) {
  const local = (value: string | null) =>
    value ? toLocalInput(parseApiDate(value)) : "";
  const [from, setFrom] = useState(local(shipment.scheduledFrom));
  const [to, setTo] = useState(local(shipment.scheduledTo));
  const [problem, setProblem] = useState("");
  return (
    <form
      className="order-form"
      onSubmit={async (e) => {
        e.preventDefault();
        setProblem("");
        // datetime-local is local time, the API wants ISO timestamps
        const start = new Date(from);
        const end = new Date(to);
        if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) {
          setProblem("Enter both ends of the window");
          return;
        }
        if (end <= start) {
          setProblem("The window must end after it starts");
          return;
        }
        await onSubmit(
          `/shipments/${shipment.id}/schedule`,
          { from: start.toISOString(), to: end.toISOString() },
          "Schedule",
        );
      }}
    >
      <h4>
        Schedule shipment #{shipment.number} ·{" "}
        {deliveryMethodLabels[shipment.method] || shipment.method}
      </h4>
      <p className="variant-note">
        The delivery or pickup window agreed with the customer.
        {shipment.status === "refused" &&
          " Re-planning a refused shipment takes its goods from own stock again."}
      </p>
      <div className="admin-form-grid">
        <label className="admin-field">
          From
          <input
            type="datetime-local"
            required
            value={from}
            onChange={(e) => setFrom(e.target.value)}
          />
        </label>
        <label className="admin-field">
          To
          <input
            type="datetime-local"
            required
            value={to}
            onChange={(e) => setTo(e.target.value)}
          />
        </label>
      </div>
      <FormFooter
        busy={busy}
        submit="Schedule"
        onCancel={onCancel}
        problem={problem}
      />
    </form>
  );
}
