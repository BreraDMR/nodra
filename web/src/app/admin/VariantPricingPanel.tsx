"use client";
import { useCallback, useEffect, useState } from "react";
import { PriceHistory } from "./PriceHistory";
import {
  ApiError,
  availabilityLabels,
  availabilityReasons,
  costBand,
  errorText,
  flagLabels,
  formatBp,
  formatCzk,
  formatDay,
  formatDays,
  formatMinor,
  formatRate,
  parseApiDate,
  type PriceApplied,
  type Send,
  type VariantPricing,
} from "./shared";

type PricedVariant = {
  id: string;
  sku: string;
  label: Record<string, string>;
  active: boolean;
};

// A source is either a link or a short note
function Source({ value }: { value: string | null }) {
  if (!value) return null;
  return /^https?:\/\//i.test(value) ? (
    <>
      {" · "}
      <a href={value} target="_blank" rel="noopener noreferrer">
        source ↗
      </a>
    </>
  ) : (
    <> · {value}</>
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

// Product editor section: pick a variant, see its pricing
export function ProductPricingPanel({
  variants,
  send,
  revision,
  onApplied,
}: {
  variants: PricedVariant[];
  send: Send;
  revision: number;
  onApplied: (applied: PriceApplied) => void;
}) {
  const [variantId, setVariantId] = useState(
    (variants.find((v) => v.active) || variants[0])?.id || "",
  );
  if (!variants.length) return null;
  return (
    <section className="offers-panel pricing-section">
      <div className="panel-head">
        <div>
          <p className="eyebrow">PRICING / VARIANT</p>
          <h2>Price &amp; availability</h2>
        </div>
      </div>
      <label className="admin-field">
        Variant
        <select
          value={variantId}
          onChange={(e) => setVariantId(e.target.value)}
        >
          {variants.map((v) => (
            <option key={v.id} value={v.id}>
              {v.label.en} ({v.sku}){v.active ? "" : " · inactive"}
            </option>
          ))}
        </select>
      </label>
      <VariantPricingPanel
        key={variantId}
        variantId={variantId}
        send={send}
        revision={revision}
        onApplied={onApplied}
      />
    </section>
  );
}

export function VariantPricingPanel({
  variantId,
  send,
  revision,
  onApplied,
}: {
  variantId: string;
  send: Send;
  revision: number;
  onApplied: (applied: PriceApplied) => void;
}) {
  const [data, setData] = useState<VariantPricing | null>(null);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [busy, setBusy] = useState(false);
  const [historyOpen, setHistoryOpen] = useState(false);
  // bumping it reloads the history after a price change
  const [historyKey, setHistoryKey] = useState(0);

  const fetchPricing = useCallback(
    () => send<VariantPricing>(`/api/admin/variants/${variantId}/pricing`),
    [send, variantId],
  );
  // revision changes when offers of the product were saved
  useEffect(() => {
    let live = true;
    fetchPricing()
      .then((result) => live && setData(result))
      .catch((e) => live && setError(errorText(e, "Could not load pricing")));
    return () => {
      live = false;
    };
  }, [fetchPricing, revision]);
  async function load() {
    try {
      setData(await fetchPricing());
    } catch (e) {
      setError(errorText(e, "Could not load pricing"));
    }
  }

  async function apply() {
    const suggestion = data?.suggestion;
    if (!data || !suggestion || !data.applicable) return;
    if (
      !confirm(
        `Set ${data.sku} to ${formatCzk(suggestion.priceCzk)} / ${formatMinor(suggestion.priceEur, "EUR")}? The change goes to the price history.`,
      )
    )
      return;
    setBusy(true);
    setError("");
    setNotice("");
    try {
      // send back exactly the suggestion shown; the API refuses it if it moved since
      const result = await send<PriceApplied>(
        `/api/admin/variants/${variantId}/pricing/apply`,
        "POST",
        { suggestedPriceCzk: suggestion.priceCzk },
      );
      setNotice(
        result.changed
          ? `Price set to ${formatCzk(result.newPriceCzk)} / ${formatMinor(result.newPriceEur, "EUR")} (was ${formatCzk(result.oldPriceCzk)} / ${formatMinor(result.oldPriceEur, "EUR")}).`
          : "The price already was the suggestion, nothing changed.",
      );
      setHistoryKey((key) => key + 1);
      onApplied(result);
    } catch (e) {
      if (e instanceof ApiError && e.status === 409)
        setError(
          "The suggestion changed since this panel was loaded, so nothing was applied. The panel now shows the new one; check it and apply again.",
        );
      else if (e instanceof ApiError && e.status === 422)
        setError(`Not applied: ${e.message}`);
      else setError(errorText(e, "Could not apply the suggestion"));
    } finally {
      await load();
      setBusy(false);
    }
  }

  if (!data)
    return error ? (
      <p className="admin-error" role="alert">
        {error}
      </p>
    ) : (
      <p className="admin-empty">Loading pricing…</p>
    );

  const { availability, cost, rule, suggestion } = data;
  const unchanged =
    suggestion !== null &&
    suggestion.priceCzk === data.priceCzk &&
    suggestion.priceEur === data.priceEur;
  const blocked = !suggestion
    ? "No suggestion yet: it needs a fresh matched offer with an exchange rate and a pricing rule for its cost."
    : data.flags.includes("margin_too_low")
      ? "Margin too low: the rule or RRP target can't keep the minimum margin. Set this price by hand in the variant editor."
      : !data.applicable
        ? "This suggestion can't be applied."
        : unchanged
          ? "The current price already matches the suggestion."
          : "";

  return (
    <div className="pricing-panel">
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
      <div className="pricing-summary">
        <div>
          <span>Current price</span>
          <strong>{formatCzk(data.priceCzk)}</strong>
          <small>
            {formatMinor(data.priceEur, "EUR")} · margin{" "}
            {data.currentMarginBp === null
              ? "— (no cost)"
              : formatBp(data.currentMarginBp)}
          </small>
        </div>
        <div>
          <span>Availability</span>
          <strong>
            <span className={`status ${availability.status}`}>
              {availabilityLabels[availability.status] || availability.status}
            </span>
          </strong>
          <small>
            {availability.status === "orderable"
              ? `Customers see ${formatDays(availability.leadTimeMinDays, availability.leadTimeMaxDays)}, handling included`
              : availabilityReasons[availability.reason || ""] ||
                availability.reason ||
                "—"}
          </small>
        </div>
      </div>
      {data.flags.length > 0 && (
        <ul className="pricing-flags">
          {data.flags.map((flag) => (
            <li key={flag} className={`flag ${flag}`}>
              <b>{flagLabels[flag] || flag}</b>
              {flag === "margin_too_low"
                ? " · the suggestion was lifted to the minimum-margin floor and is never applied automatically"
                : " · the current price is over the threshold above the market price (information only)"}
            </li>
          ))}
        </ul>
      )}

      <h3>Landed cost</h3>
      {cost ? (
        <dl className="pricing-rows">
          <Row label="Best offer">
            {cost.supplier.replaceAll("_", " ")}
            {cost.seller ? ` · ${cost.seller}` : ""} ·{" "}
            <a href={cost.url} target="_blank" rel="noopener noreferrer">
              open ↗
            </a>
            <small>
              checked{" "}
              {parseApiDate(cost.checkedAt).toLocaleString("en-GB", {
                dateStyle: "medium",
                timeStyle: "short",
              })}{" "}
              · supplier lead time{" "}
              {formatDays(cost.leadTimeMinDays, cost.leadTimeMaxDays)}
            </small>
          </Row>
          <Row label="Offer price">
            {formatMinor(cost.priceMinor, cost.currency)}
          </Row>
          <Row label="Inbound shipping">
            {formatMinor(cost.inboundShippingMinor, cost.currency)}
          </Row>
          {cost.currency !== "CZK" && (
            <Row label="Exchange rate">
              {formatRate(cost.fxRateCzk)} CZK / {cost.currency}
              <small>rate of {formatDay(cost.fxRateDate)}</small>
            </Row>
          )}
          <Row label="Landed cost">
            <b>{formatCzk(cost.landedCostCzk)}</b>
          </Row>
        </dl>
      ) : (
        <p className="variant-note">
          No usable offer: the cost needs a matched, fresh offer with a known
          exchange rate.
        </p>
      )}

      <h3>Rule and suggestion</h3>
      <dl className="pricing-rows">
        <Row label="Rule used">
          {rule ? (
            <>
              +{formatBp(rule.markupBp)} · {costBand(rule)}
              <small>
                {rule.categoryId === null
                  ? "default rule, all categories"
                  : `category ${rule.categorySlug}`}
              </small>
            </>
          ) : cost ? (
            "— no rule covers this cost"
          ) : (
            "— needs a landed cost first"
          )}
        </Row>
        {suggestion && (
          <>
            <Row label="Cost + markup">
              {formatCzk(suggestion.markupPriceCzk)}
            </Row>
            <Row label="RRP cap">
              {suggestion.rrpCapCzk === null
                ? "— no RRP"
                : formatCzk(suggestion.rrpCapCzk)}
              {suggestion.rrpCapped && <small>the cap lowered the price</small>}
            </Row>
            <Row label="Minimum-margin floor">
              {formatCzk(suggestion.floorPriceCzk)}
            </Row>
            <Row label="Suggested price">
              <b>
                {formatCzk(suggestion.priceCzk)} /{" "}
                {formatMinor(suggestion.priceEur, "EUR")}
              </b>
              <small>
                margin {formatBp(suggestion.marginBp)}
                {suggestion.aboveMarket && (
                  <span className="flag-inline"> · above market</span>
                )}
              </small>
            </Row>
          </>
        )}
      </dl>

      <h3>References</h3>
      <dl className="pricing-rows">
        <Row label="RRP">
          {data.rrpMinor === null || !data.rrpCurrency ? (
            "—"
          ) : (
            <>
              {formatMinor(data.rrpMinor, data.rrpCurrency)}
              {data.rrpCurrency !== "CZK" && data.rrpCzk !== null && (
                <> ≈ {formatCzk(data.rrpCzk)}</>
              )}
              <small>
                checked {formatDay(data.rrpCheckedAt)}
                <Source value={data.rrpSource} />
              </small>
            </>
          )}
        </Row>
        <Row label="Market price">
          {data.marketPriceMinor === null ? (
            "—"
          ) : (
            <>
              {formatCzk(data.marketPriceMinor)}
              <small>
                checked {formatDay(data.marketCheckedAt)}
                <Source value={data.marketPriceSource} />
              </small>
            </>
          )}
        </Row>
      </dl>
      <p className="variant-note">
        RRP and market price are set in the variant editor and never shown to
        customers.
      </p>

      <div className="pricing-apply">
        <button
          type="button"
          className="admin-primary"
          disabled={busy || !data.applicable || unchanged}
          onClick={apply}
        >
          Apply suggestion
          {suggestion ? ` · ${formatCzk(suggestion.priceCzk)}` : ""} ↗
        </button>
        <button
          type="button"
          className="admin-secondary"
          disabled={busy}
          onClick={() => {
            setError("");
            setNotice("");
            void load();
          }}
        >
          ↻ Reload
        </button>
      </div>
      {blocked && <p className="variant-note">{blocked}</p>}

      <button
        type="button"
        className="admin-link history-toggle"
        onClick={() => setHistoryOpen(!historyOpen)}
        aria-expanded={historyOpen}
      >
        {historyOpen ? "Hide price history ↑" : "Show price history ↓"}
      </button>
      {historyOpen && (
        <PriceHistory key={historyKey} variantId={variantId} send={send} />
      )}
    </div>
  );
}
