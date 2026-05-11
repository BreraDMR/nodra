"use client";
import { useState } from "react";
import {
  errorText,
  formatDay,
  formatMinor,
  formatRate,
  minorText,
  moneyHint,
  moneyPattern,
  offerStatuses,
  parseApiDate,
  parseMinor,
  parseRate,
  suppliers,
  toLocalInput,
  type Save,
  type SupplierOffer,
} from "./shared";

// CZK per CZK, the only rate a CZK offer can have
const czkRate = 1000000;

type OfferVariant = { id: string; sku: string; label: Record<string, string> };
type OfferForm = {
  supplier: string;
  url: string;
  title: string;
  seller: string;
  currency: string;
  // amounts typed in the offer currency, the rate as CZK per unit ("25.315")
  price: string;
  inboundShipping: string;
  fxRate: string;
  fxRateDate: string;
  reportedQuantity: string;
  checkedAt: string;
  leadTimeMinDays: string;
  leadTimeMaxDays: string;
  variantId: string;
  verificationStatus: string;
};

function blank(): OfferForm {
  return {
    supplier: "allegro_cz",
    url: "",
    title: "",
    seller: "",
    currency: "CZK",
    price: "",
    inboundShipping: "",
    fxRate: "",
    fxRateDate: "",
    reportedQuantity: "",
    checkedAt: toLocalInput(new Date()),
    leadTimeMinDays: "",
    leadTimeMaxDays: "",
    variantId: "",
    verificationStatus: "snapshot",
  };
}

function fromOffer(offer: SupplierOffer): OfferForm {
  const text = (n: number | null) => (n === null ? "" : String(n));
  return {
    supplier: offer.supplier,
    url: offer.url,
    title: offer.title,
    seller: offer.seller || "",
    currency: offer.currency,
    price: minorText(offer.priceMinor),
    inboundShipping: minorText(offer.inboundShippingMinor),
    fxRate:
      offer.currency === "CZK" || offer.fxRateCzk === null
        ? ""
        : formatRate(offer.fxRateCzk),
    fxRateDate: (offer.fxRateDate || "").slice(0, 10),
    reportedQuantity: text(offer.reportedQuantity),
    checkedAt: toLocalInput(parseApiDate(offer.checkedAt)),
    leadTimeMinDays: text(offer.leadTimeMinDays),
    leadTimeMaxDays: text(offer.leadTimeMaxDays),
    variantId: offer.variantId || "",
    verificationStatus: offer.verificationStatus,
  };
}

function rate(offer: SupplierOffer): string {
  if (offer.currency === "CZK") return "no exchange";
  if (offer.fxRateCzk === null) return "exchange rate missing, can't be priced";
  return `rate ${formatRate(offer.fxRateCzk)} CZK/${offer.currency}${offer.fxRateDate ? ` of ${formatDay(offer.fxRateDate)}` : ""}`;
}

function leadTime(offer: SupplierOffer): string {
  const { leadTimeMinDays: min, leadTimeMaxDays: max } = offer;
  if (min === null && max === null) return "not set";
  if (min !== null && max !== null)
    return min === max ? `${min} days` : `${min}–${max} days`;
  return min !== null ? `from ${min} days` : `up to ${max} days`;
}

export function SupplierOffersPanel({
  productId,
  offers,
  variants,
  busy,
  error,
  save,
}: {
  productId: string;
  offers: SupplierOffer[];
  variants: OfferVariant[];
  busy: boolean;
  error: string;
  save: Save;
}) {
  // null = closed, "new" = adding, otherwise the offer id
  const [editing, setEditing] = useState<string | null>(null);
  const [form, setForm] = useState<OfferForm>(blank);
  const [formError, setFormError] = useState("");
  const set = (patch: Partial<OfferForm>) => setForm({ ...form, ...patch });
  const variantName = (id: string | null) => {
    const v = variants.find((x) => x.id === id);
    return v ? `${v.label.en} (${v.sku})` : "—";
  };

  async function submit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setFormError("");
    const num = (value: string) => (value.trim() === "" ? null : Number(value));
    const checkedAt = new Date(form.checkedAt);
    let money;
    try {
      const priceMinor = parseMinor(form.price);
      if (priceMinor === null) throw Error("Enter the offer price");
      money = {
        priceMinor,
        inboundShippingMinor: parseMinor(form.inboundShipping) ?? 0,
        // a CZK offer always has rate 1; EUR/PLN without a rate stays unpriced
        fxRateCzk: form.currency === "CZK" ? czkRate : parseRate(form.fxRate),
        fxRateDate: form.fxRateDate || null,
      };
      if (money.fxRateCzk === 0)
        throw Error("The exchange rate must be above 0");
    } catch (err) {
      setFormError(errorText(err, "Check the amounts"));
      return;
    }
    // PUT replaces the whole offer, so every field goes out, changed or not
    const ok = await save(
      editing === "new"
        ? `/api/admin/products/${productId}/supplier-offers`
        : `/api/admin/supplier-offers/${editing}`,
      editing === "new" ? "POST" : "PUT",
      {
        supplier: form.supplier,
        url: form.url.trim(),
        title: form.title.trim(),
        seller: form.seller.trim() || null,
        currency: form.currency,
        ...money,
        reportedQuantity: num(form.reportedQuantity),
        // datetime-local is local time, the API wants an ISO timestamp
        checkedAt: Number.isNaN(checkedAt.getTime())
          ? form.checkedAt
          : checkedAt.toISOString(),
        leadTimeMinDays: num(form.leadTimeMinDays),
        leadTimeMaxDays: num(form.leadTimeMaxDays),
        variantId: form.variantId || null,
        verificationStatus: form.verificationStatus,
      },
    );
    if (ok) setEditing(null);
  }

  return (
    <section className="offers-panel">
      <div className="panel-head">
        <div>
          <p className="eyebrow">PROCUREMENT / SOURCES</p>
          <h2>Supplier offers</h2>
        </div>
        <button
          type="button"
          onClick={() => {
            setForm(blank());
            setFormError("");
            setEditing("new");
          }}
        >
          + Add offer
        </button>
      </div>
      {!offers.length && (
        <p className="admin-empty">No supplier offers recorded yet.</p>
      )}
      {offers.map((offer) => (
        <div className="source-card" key={offer.id}>
          <div className="offer-head">
            <strong>
              {offer.supplier.replaceAll("_", " ")}
              {offer.seller ? ` · ${offer.seller}` : ""}
            </strong>
            <span className={`status ${offer.verificationStatus}`}>
              {offer.verificationStatus}
            </span>
          </div>
          <p>{offer.title}</p>
          <p>
            {formatMinor(offer.priceMinor, offer.currency)} · reported quantity{" "}
            {offer.reportedQuantity ?? "unconfirmed"} · checked{" "}
            {parseApiDate(offer.checkedAt).toLocaleString("en-GB", {
              dateStyle: "medium",
              timeStyle: "short",
            })}
          </p>
          <p>
            Inbound shipping{" "}
            {formatMinor(offer.inboundShippingMinor, offer.currency)} ·{" "}
            {rate(offer)}
          </p>
          <p>
            Lead time {leadTime(offer)} · variant {variantName(offer.variantId)}
          </p>
          <div className="offer-actions">
            <a href={offer.url} target="_blank" rel="noopener noreferrer">
              Open offer ↗
            </a>
            <button
              type="button"
              className="admin-link"
              onClick={() => {
                setForm(fromOffer(offer));
                setFormError("");
                setEditing(offer.id);
              }}
            >
              Edit
            </button>
          </div>
        </div>
      ))}
      {editing && (
        <form className="offer-form" onSubmit={submit}>
          <h3>{editing === "new" ? "New offer" : "Edit offer"}</h3>
          <div className="admin-form-grid">
            <label className="admin-field">
              Supplier
              <select
                value={form.supplier}
                onChange={(e) => set({ supplier: e.target.value })}
              >
                {suppliers.map((s) => (
                  <option key={s} value={s}>
                    {s.replaceAll("_", " ")}
                  </option>
                ))}
              </select>
            </label>
            <label className="admin-field">
              Seller
              <input
                value={form.seller}
                maxLength={120}
                onChange={(e) => set({ seller: e.target.value })}
              />
            </label>
            <label className="admin-field wide">
              Listing URL
              <input
                type="url"
                value={form.url}
                maxLength={2048}
                onChange={(e) => set({ url: e.target.value })}
                required
              />
            </label>
            <label className="admin-field wide">
              Listing title
              <input
                value={form.title}
                maxLength={200}
                onChange={(e) => set({ title: e.target.value })}
                required
              />
            </label>
            <label className="admin-field">
              Currency
              <select
                value={form.currency}
                onChange={(e) =>
                  // a rate belongs to its currency; the API drops it on a change too
                  set({ currency: e.target.value, fxRate: "", fxRateDate: "" })
                }
              >
                {["CZK", "EUR", "PLN"].map((c) => (
                  <option key={c}>{c}</option>
                ))}
              </select>
            </label>
            <label className="admin-field">
              Price · {form.currency}
              <input
                inputMode="decimal"
                pattern={moneyPattern}
                title={moneyHint}
                placeholder="e.g. 49.90"
                value={form.price}
                onChange={(e) => set({ price: e.target.value })}
                required
              />
            </label>
            <label className="admin-field">
              Inbound shipping · {form.currency} per unit
              <input
                inputMode="decimal"
                pattern={moneyPattern}
                title={moneyHint}
                placeholder="0 = free"
                value={form.inboundShipping}
                onChange={(e) => set({ inboundShipping: e.target.value })}
              />
            </label>
            {form.currency !== "CZK" && (
              <>
                <label className="admin-field">
                  Rate · CZK per 1 {form.currency}
                  <input
                    inputMode="decimal"
                    pattern="\s*\d+([.,]\d{0,6})?\s*"
                    title="CZK for one unit, up to 6 decimals, e.g. 25.315"
                    placeholder="e.g. 25.315"
                    value={form.fxRate}
                    onChange={(e) => set({ fxRate: e.target.value })}
                  />
                </label>
                <label className="admin-field">
                  Rate date
                  <input
                    type="date"
                    value={form.fxRateDate}
                    onChange={(e) => set({ fxRateDate: e.target.value })}
                  />
                </label>
              </>
            )}
            <label className="admin-field">
              Reported quantity
              <input
                type="number"
                min="0"
                step="1"
                value={form.reportedQuantity}
                placeholder="unconfirmed"
                onChange={(e) => set({ reportedQuantity: e.target.value })}
              />
            </label>
            <label className="admin-field">
              Checked at
              <input
                type="datetime-local"
                value={form.checkedAt}
                onChange={(e) => set({ checkedAt: e.target.value })}
                required
              />
            </label>
            <label className="admin-field">
              Lead time · min days
              <input
                type="number"
                min="0"
                max="365"
                value={form.leadTimeMinDays}
                onChange={(e) => set({ leadTimeMinDays: e.target.value })}
              />
            </label>
            <label className="admin-field">
              Lead time · max days
              <input
                type="number"
                min="0"
                max="365"
                value={form.leadTimeMaxDays}
                onChange={(e) => set({ leadTimeMaxDays: e.target.value })}
              />
            </label>
            <label className="admin-field">
              Matched variant
              <select
                value={form.variantId}
                onChange={(e) => set({ variantId: e.target.value })}
                required={form.verificationStatus === "matched"}
              >
                <option value="">— not matched —</option>
                {variants.map((v) => (
                  <option key={v.id} value={v.id}>
                    {v.label.en} ({v.sku})
                  </option>
                ))}
              </select>
            </label>
            <label className="admin-field">
              Status
              <select
                value={form.verificationStatus}
                onChange={(e) => set({ verificationStatus: e.target.value })}
              >
                {offerStatuses.map((s) => (
                  <option key={s}>{s}</option>
                ))}
              </select>
            </label>
          </div>
          {form.verificationStatus === "matched" && !form.variantId && (
            <p className="variant-note">
              A matched offer needs the exact variant.
            </p>
          )}
          {form.currency !== "CZK" && !form.fxRate.trim() && (
            <p className="variant-note">
              Without an exchange rate this offer isn&apos;t used for cost or
              availability.
            </p>
          )}
          {(formError || error) && (
            <p className="admin-error" role="alert">
              {formError || error}
            </p>
          )}
          <div className="offer-actions">
            <button className="admin-primary" disabled={busy}>
              Save offer ↗
            </button>
            <button
              type="button"
              className="admin-link"
              onClick={() => setEditing(null)}
            >
              Cancel
            </button>
          </div>
        </form>
      )}
    </section>
  );
}
