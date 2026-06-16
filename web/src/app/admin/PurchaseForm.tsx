"use client";
import { useRef, useState } from "react";
import {
  allocatePurchase,
  purchaseCurrencies,
  purchaseProblemText,
  RATE_ONE,
  supplierLabel,
  type AdminPurchase,
  type CreatePurchaseBody,
  type PurchaseCurrency,
  type ToPurchaseLine,
} from "./purchases";
import {
  ApiError,
  errorText,
  formatCzk,
  formatDay,
  formatMinor,
  formatRate,
  minorText,
  moneyHint,
  moneyPattern,
  newKey,
  parseMinor,
  parseRate,
  suppliers,
  type Send,
} from "./shared";

// what a price field starts with: the offer price, when it's in the purchase currency
function prefill(line: ToPurchaseLine, currency: string): string {
  return line.offer && line.offer.currency === currency
    ? minorText(line.offer.priceMinor)
    : "";
}

// the one value every line shares, or null
function common<T>(values: T[]): T | null {
  return values.length && values.every((v) => v === values[0])
    ? values[0]
    : null;
}

function parsed(text: string, parse: (t: string) => number | null) {
  try {
    return parse(text);
  } catch {
    return undefined;
  }
}

export function PurchaseForm({
  supplier: groupSupplier,
  lines,
  send,
  onCreated,
  onStale,
  onCancel,
}: {
  // the group's supplier; null = the no-source group, the admin picks one
  supplier: string | null;
  lines: ToPurchaseLine[];
  send: Send;
  onCreated: (purchase: AdminPurchase) => void;
  // a line can't be bought any more: the to-purchase list should reload
  onStale: () => void;
  onCancel: () => void;
}) {
  const offers = lines.flatMap((l) => (l.offer ? [l.offer] : []));
  const offerCurrency = common(offers.map((o) => o.currency));
  const [supplier, setSupplier] = useState(groupSupplier ?? "");
  const [seller, setSeller] = useState(
    () => common(offers.map((o) => o.seller ?? "")) ?? "",
  );
  const [reference, setReference] = useState("");
  const [currency, setCurrency] = useState<PurchaseCurrency>(
    purchaseCurrencies.find((c) => c === offerCurrency) ?? "CZK",
  );
  const [rate, setRate] = useState("");
  const [rateDate, setRateDate] = useState("");
  const [shipping, setShipping] = useState("");
  const [note, setNote] = useState("");
  const [prices, setPrices] = useState<Record<string, string>>(() =>
    Object.fromEntries(lines.map((l) => [l.itemId, prefill(l, currency)])),
  );
  // prices typed by hand stay when the currency changes
  const [typed, setTyped] = useState<Set<string>>(() => new Set());
  const [problem, setProblem] = useState("");
  const [busy, setBusy] = useState(false);
  // One Idempotency-Key per purchase: a retry of exactly the same body after a lost
  // answer reuses it, so the server hands back the first purchase instead of a second
  const pending = useRef<{ body: string; key: string } | null>(null);

  const czk = currency === "CZK";
  const rateOffer = common(
    offers.filter((o) => o.currency === currency).map((o) => o.fxRateCzk),
  );
  const skuOf = (itemId: string) =>
    lines.find((l) => l.itemId === itemId)?.sku ?? "a line";

  function changeCurrency(next: PurchaseCurrency) {
    setCurrency(next);
    setPrices((current) =>
      Object.fromEntries(
        lines.map((l) => [
          l.itemId,
          typed.has(l.itemId) ? current[l.itemId] : prefill(l, next),
        ]),
      ),
    );
  }

  // The live preview needs every price, the shipping and the rate
  const previewRate = czk ? RATE_ONE : parsed(rate, parseRate);
  const previewShipping = parsed(shipping, parseMinor);
  const previewPrices = lines.map((l) =>
    parsed(prices[l.itemId] ?? "", parseMinor),
  );
  const preview =
    previewRate &&
    previewShipping !== undefined &&
    previewPrices.every((p) => typeof p === "number")
      ? allocatePurchase(
          lines.map((l, i) => ({
            itemId: l.itemId,
            quantity: l.quantity,
            unitPriceMinor: previewPrices[i] as number,
          })),
          previewShipping ?? 0,
          previewRate,
        )
      : null;
  const previewOf = new Map(preview?.map((p) => [p.itemId, p]));
  const goodsMinor = preview?.reduce(
    (sum, p) => sum + p.unitPriceMinor * p.quantity,
    0,
  );
  const costMinor = preview?.reduce(
    (sum, p) => sum + p.unitCostCzkMinor * p.quantity,
    0,
  );

  function body(): CreatePurchaseBody {
    if (!supplier) throw Error("Pick the supplier");
    if (!reference.trim())
      throw Error("Enter the supplier's order number as the reference");
    const fxRateCzk = czk ? null : parseRate(rate);
    if (!czk && !fxRateCzk)
      throw Error(`Enter the rate the ${currency} purchase was paid at`);
    const inboundShippingMinor = parseMinor(shipping) ?? 0;
    return {
      supplier,
      seller: seller.trim() || null,
      reference: reference.trim(),
      currency,
      fxRateCzk,
      fxRateDate: czk ? null : rateDate || null,
      inboundShippingMinor,
      note: note.trim() || null,
      lines: lines.map((l) => {
        const unitPriceMinor = parseMinor(prices[l.itemId] ?? "");
        if (unitPriceMinor === null)
          throw Error(`Enter the unit price of ${l.sku}`);
        return { itemId: l.itemId, unitPriceMinor };
      }),
    };
  }

  async function submit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setProblem("");
    let payload: CreatePurchaseBody;
    try {
      payload = body();
    } catch (err) {
      setProblem(errorText(err, "Check the purchase"));
      return;
    }
    const text = JSON.stringify(payload);
    if (pending.current?.body !== text)
      pending.current = { body: text, key: newKey("purchase") };
    setBusy(true);
    try {
      const purchase = await send<AdminPurchase>(
        "/api/admin/purchases",
        "POST",
        payload,
        { "Idempotency-Key": pending.current.key },
      );
      pending.current = null;
      onCreated(purchase);
    } catch (err) {
      // no answer (or a proxy error): it may be saved, so keep the key for a retry
      const unknown = !(err instanceof ApiError) || err.status >= 500;
      if (unknown) {
        setProblem(
          `${errorText(err, "No answer from the server")}. It's unclear whether the purchase was recorded. Press Create purchase again without changing anything: the same key makes the retry safe.`,
        );
      } else {
        pending.current = null;
        setProblem(purchaseProblemText(err, "Create purchase", skuOf));
        if (err.code === "line_not_purchasable") onStale();
      }
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="order-form purchase-form" onSubmit={submit}>
      <p className="variant-note">
        Place the order on the supplier&apos;s site first; this only records it.
        The lines become ordered with the reference as their supplier ref. Money
        is typed as text, e.g. 1290 or 51.60.
      </p>
      <div className="admin-form-grid">
        <label className="admin-field">
          Supplier
          {groupSupplier ? (
            <input value={supplierLabel(groupSupplier)} readOnly />
          ) : (
            <select
              value={supplier}
              required
              onChange={(e) => setSupplier(e.target.value)}
            >
              <option value="">— pick a supplier —</option>
              {suppliers.map((s) => (
                <option key={s} value={s}>
                  {supplierLabel(s)}
                </option>
              ))}
            </select>
          )}
        </label>
        <label className="admin-field">
          Seller · optional
          <input
            maxLength={120}
            value={seller}
            onChange={(e) => setSeller(e.target.value)}
          />
        </label>
        <label className="admin-field">
          Reference · supplier&apos;s order number
          <input
            maxLength={120}
            required
            value={reference}
            onChange={(e) => setReference(e.target.value)}
          />
        </label>
        <label className="admin-field">
          Currency
          <select
            value={currency}
            onChange={(e) => changeCurrency(e.target.value as PurchaseCurrency)}
          >
            {purchaseCurrencies.map((c) => (
              <option key={c} value={c}>
                {c}
              </option>
            ))}
          </select>
        </label>
        {!czk && (
          <>
            <label className="admin-field">
              Rate · CZK per {currency}, as paid
              <input
                inputMode="decimal"
                pattern="\s*\d+([.,]\d{0,6})?\s*"
                title="A number with up to 6 decimals, e.g. 25.315"
                placeholder={
                  rateOffer ? `offer: ${formatRate(rateOffer)}` : "e.g. 25.315"
                }
                required
                value={rate}
                onChange={(e) => setRate(e.target.value)}
              />
            </label>
            <label className="admin-field">
              Rate date · optional
              <input
                type="date"
                value={rateDate}
                onChange={(e) => setRateDate(e.target.value)}
              />
            </label>
          </>
        )}
        <label className="admin-field">
          Inbound shipping · whole purchase · {currency}
          <input
            inputMode="decimal"
            pattern={moneyPattern}
            title={moneyHint}
            placeholder="0"
            value={shipping}
            onChange={(e) => setShipping(e.target.value)}
          />
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

      <div className="admin-table-wrap">
        <table className="admin-table compact-table purchase-lines">
          <thead>
            <tr>
              <th>LINE</th>
              <th>QTY</th>
              <th>UNIT PRICE · {currency}</th>
              <th>SHIPPING SHARE</th>
              <th>ACTUAL UNIT COST</th>
              <th>SNAPSHOT</th>
            </tr>
          </thead>
          <tbody>
            {lines.map((l) => {
              const p = previewOf.get(l.itemId);
              const diff =
                p && l.snapshotUnitCostCzkMinor !== null
                  ? p.unitCostCzkMinor - l.snapshotUnitCostCzkMinor
                  : null;
              return (
                <tr key={l.itemId}>
                  <td>
                    <strong>{l.sku}</strong>
                    <small>
                      {l.name} / {l.variant} · {l.orderReference}
                    </small>
                    {l.offer && (
                      <small>
                        offer{" "}
                        {formatMinor(l.offer.priceMinor, l.offer.currency)}
                      </small>
                    )}
                  </td>
                  <td>{l.quantity}</td>
                  <td>
                    <input
                      className="purchase-price"
                      inputMode="decimal"
                      pattern={moneyPattern}
                      title={moneyHint}
                      aria-label={`Unit price of ${l.sku}`}
                      required
                      value={prices[l.itemId] ?? ""}
                      onChange={(e) => {
                        const value = e.target.value;
                        setPrices((current) => ({
                          ...current,
                          [l.itemId]: value,
                        }));
                        setTyped((current) => new Set(current).add(l.itemId));
                      }}
                    />
                  </td>
                  <td>
                    {p ? formatMinor(p.allocatedShippingMinor, currency) : "—"}
                  </td>
                  <td>
                    <b>{p ? formatCzk(p.unitCostCzkMinor) : "—"}</b>
                  </td>
                  <td>
                    {l.snapshotUnitCostCzkMinor === null
                      ? "—"
                      : formatCzk(l.snapshotUnitCostCzkMinor)}
                    {diff !== null && diff !== 0 && (
                      <small
                        className={diff > 0 ? "order-refund" : "cost-lower"}
                      >
                        {diff > 0 ? "+" : "−"}
                        {formatCzk(Math.abs(diff))} vs snapshot
                      </small>
                    )}
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
      <p className="purchase-preview-note">
        <span className="preview-tag">Preview</span>{" "}
        {preview && goodsMinor !== undefined && costMinor !== undefined
          ? `Goods ${formatMinor(goodsMinor, currency)} + shipping ${formatMinor(previewShipping ?? 0, currency)} → actual cost ${formatCzk(costMinor)}. Shipping is split by line value, rounded down, the rest to the largest line; the same maths the server uses.`
          : previewShipping === undefined
            ? "The inbound shipping isn't an amount yet, e.g. 89 or 4.50."
            : `Fill in every unit price${czk ? "" : " and the rate"} to see the shipping split and the actual cost.`}
        {!czk && previewRate ? ` Rate ${formatRate(previewRate)}.` : ""}
      </p>
      {lines.some((l) => l.promisedDate) && (
        <p className="variant-note">
          Promised to the customer:{" "}
          {lines
            .filter((l) => l.promisedDate)
            .map((l) => `${l.sku} by ${formatDay(l.promisedDate)}`)
            .join(", ")}
          .
        </p>
      )}
      {problem && (
        <p className="admin-error" role="alert">
          {problem}
        </p>
      )}
      <div className="order-form-actions">
        <button className="admin-primary" disabled={busy}>
          {busy ? "Recording…" : "Create purchase ↗"}
        </button>
        <button type="button" className="admin-secondary" onClick={onCancel}>
          Close
        </button>
      </div>
    </form>
  );
}
