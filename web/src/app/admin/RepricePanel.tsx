"use client";
import { useCallback, useEffect, useState } from "react";
import {
  ApiError,
  categoryPath,
  errorText,
  flagLabels,
  formatBp,
  formatCzk,
  formatMinor,
  indent,
  type AdminCategory,
  type PriceApplied,
  type RepricePreview,
  type RepriceRow,
  type Send,
} from "./shared";

export type RepriceFilter =
  "all" | "changed" | "margin_too_low" | "above_market";

// the API takes at most this many items per apply
const maxItems = 500;

// Only rows whose suggestion differs and isn't flagged can be applied
const selectable = (row: RepriceRow) => row.applicable && row.changed;

function matches(row: RepriceRow, filter: RepriceFilter): boolean {
  if (filter === "changed") return row.changed;
  if (filter === "margin_too_low") return row.flags.includes("margin_too_low");
  if (filter === "above_market")
    return row.flags.includes("above_market") || row.suggestionAboveMarket;
  return true;
}

export function RepricePanel({
  categories,
  send,
  onApplied,
  initialFilter = "all",
}: {
  categories: AdminCategory[];
  send: Send;
  onApplied: () => void;
  initialFilter?: RepriceFilter;
}) {
  // "" = the whole catalogue
  const [scope, setScope] = useState("");
  const [preview, setPreview] = useState<RepricePreview | null>(null);
  const [filter, setFilter] = useState<RepriceFilter>(initialFilter);
  const [selected, setSelected] = useState<Set<string>>(new Set());
  // rows the last apply found changed; marked until the next preview
  const [stale, setStale] = useState<Set<string>>(new Set());
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");

  const fetchPreview = useCallback(
    (categoryId: string) =>
      send<RepricePreview>(
        `/api/admin/pricing/reprice${categoryId ? `?categoryId=${encodeURIComponent(categoryId)}` : ""}`,
      ),
    [send],
  );
  // the whole catalogue is small enough to show straight away
  useEffect(() => {
    let live = true;
    fetchPreview("")
      .then((result) => live && setPreview(result))
      .catch((e) => live && setError(errorText(e, "Could not load preview")));
    return () => {
      live = false;
    };
  }, [fetchPreview]);

  // keep = selection to carry over, as far as those rows can still be applied
  async function load(categoryId: string, keep: Set<string> = new Set()) {
    setBusy(true);
    try {
      const result = await fetchPreview(categoryId);
      const allowed = new Set(
        result.rows.filter(selectable).map((row) => row.variantId),
      );
      setPreview(result);
      setSelected(new Set([...keep].filter((id) => allowed.has(id))));
    } catch (e) {
      setError(errorText(e, "Could not load preview"));
    } finally {
      setBusy(false);
    }
  }

  async function apply() {
    if (!preview) return;
    const items = preview.rows
      .filter((row) => selected.has(row.variantId) && selectable(row))
      .map((row) => ({
        variantId: row.variantId,
        suggestedPriceCzk: row.suggestedPriceCzk,
      }));
    if (!items.length || items.length > maxItems) return;
    if (
      !confirm(
        `Apply ${items.length} suggested ${items.length === 1 ? "price" : "prices"}? Live prices change and each change goes to the price history.`,
      )
    )
      return;
    const categoryId = preview.categoryId || "";
    setBusy(true);
    setError("");
    setNotice("");
    setStale(new Set());
    try {
      const result = await send<{ applied: number; items: PriceApplied[] }>(
        "/api/admin/pricing/reprice",
        "POST",
        { items },
      );
      setNotice(
        `${result.applied} of ${items.length} ${items.length === 1 ? "price" : "prices"} changed.`,
      );
      onApplied();
      await load(categoryId);
    } catch (e) {
      if (e instanceof ApiError && e.status === 409) {
        // all or nothing: nothing was written, show which rows moved
        const ids = new Set(e.staleVariantIds);
        const skus = preview.rows
          .filter((row) => ids.has(row.variantId))
          .map((row) => row.sku);
        setError(
          `Nothing was applied: ${ids.size} ${ids.size === 1 ? "suggestion" : "suggestions"} changed since the preview${skus.length ? ` (${skus.join(", ")})` : ""}. The preview is reloaded and those rows are marked; check them and apply again.`,
        );
        setStale(ids);
        await load(
          categoryId,
          new Set([...selected].filter((id) => !ids.has(id))),
        );
      } else {
        setError(errorText(e, "Could not apply prices"));
      }
    } finally {
      setBusy(false);
    }
  }

  const rows = (preview?.rows || []).filter((row) => matches(row, filter));
  const visibleSelectable = rows.filter(selectable);
  const chosen = (preview?.rows || []).filter(
    (row) => selected.has(row.variantId) && selectable(row),
  ).length;
  const toggle = (id: string) => {
    const next = new Set(selected);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    setSelected(next);
  };

  return (
    <>
      <div className="admin-heading compact">
        <div>
          <p className="eyebrow">PRICING / REPRICE</p>
          <h1>
            Reprice<span>.</span>
          </h1>
          <p>
            Current prices against what the rules suggest. Nothing changes until
            you apply the rows you tick.
          </p>
        </div>
      </div>
      <form
        className="reprice-controls"
        onSubmit={(e) => {
          e.preventDefault();
          setNotice("");
          setError("");
          setStale(new Set());
          void load(scope);
        }}
      >
        <label className="admin-field">
          Scope
          <select value={scope} onChange={(e) => setScope(e.target.value)}>
            <option value="">Whole catalogue</option>
            {categories.map((c) => (
              <option key={c.id} value={c.id}>
                {indent(c)}
                {c.active ? "" : " (inactive)"} · with subcategories
              </option>
            ))}
          </select>
        </label>
        <label className="admin-field">
          Show
          <select
            value={filter}
            onChange={(e) => setFilter(e.target.value as RepriceFilter)}
          >
            <option value="all">All rows with a suggestion</option>
            <option value="changed">Only changed prices</option>
            <option value="margin_too_low">Margin too low</option>
            <option value="above_market">Above market</option>
          </select>
        </label>
        <button className="admin-secondary" disabled={busy}>
          Preview ↗
        </button>
      </form>
      {notice && (
        <div className="admin-success" role="status">
          ✓ {notice}
          <button onClick={() => setNotice("")}>×</button>
        </div>
      )}
      {error && (
        <div className="admin-error" role="alert">
          {error}
          <button onClick={() => setError("")}>×</button>
        </div>
      )}
      {preview && (
        <p className="reprice-summary">
          <b>
            {preview.categoryId
              ? categoryPath(preview.categoryId, categories, "Category")
              : "Whole catalogue"}
          </b>{" "}
          · {preview.variants} active variants · {preview.rows.length} with a
          suggestion · {preview.withoutSuggestion} without one (no fresh matched
          offer or no rule)
        </p>
      )}
      <div className="reprice-actions">
        <button
          type="button"
          className="admin-secondary"
          disabled={busy || !visibleSelectable.length}
          onClick={() =>
            setSelected(
              new Set([
                ...selected,
                ...visibleSelectable.map((row) => row.variantId),
              ]),
            )
          }
        >
          Select all applicable ({visibleSelectable.length})
        </button>
        <button
          type="button"
          className="admin-secondary"
          disabled={busy || !selected.size}
          onClick={() => setSelected(new Set())}
        >
          Clear selection
        </button>
        <button
          type="button"
          className="admin-primary"
          disabled={busy || !chosen || chosen > maxItems}
          onClick={apply}
        >
          Apply {chosen} selected ↗
        </button>
        {chosen > maxItems && (
          <span className="variant-note">
            At most {maxItems} rows per apply.
          </span>
        )}
      </div>
      <div className="admin-table-wrap">
        <table className="admin-table reprice-table">
          <thead>
            <tr>
              <th aria-label="Select"></th>
              <th>PRODUCT</th>
              <th>COST</th>
              <th>CURRENT</th>
              <th>SUGGESTED</th>
              <th>MARKET</th>
              <th>FLAGS</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr
                key={row.variantId}
                className={stale.has(row.variantId) ? "stale-row" : undefined}
              >
                <td>
                  {selectable(row) && (
                    <input
                      type="checkbox"
                      aria-label={`Select ${row.sku}`}
                      checked={selected.has(row.variantId)}
                      onChange={() => toggle(row.variantId)}
                    />
                  )}
                </td>
                <td>
                  <strong>{row.productName}</strong>
                  <small>
                    {row.sku} · {row.categorySlug}
                  </small>
                  {stale.has(row.variantId) && (
                    <small className="stale-note">
                      changed since the last preview
                    </small>
                  )}
                </td>
                <td>{formatCzk(row.landedCostCzk)}</td>
                <td>
                  {formatCzk(row.priceCzk)}
                  <small>
                    {formatMinor(row.priceEur, "EUR")} · margin{" "}
                    {formatBp(row.currentMarginBp)}
                  </small>
                </td>
                <td>
                  <b className={row.changed ? "price-changed" : undefined}>
                    {formatCzk(row.suggestedPriceCzk)}
                  </b>
                  <small>
                    {formatMinor(row.suggestedPriceEur, "EUR")} · margin{" "}
                    {formatBp(row.suggestedMarginBp)}
                    {row.changed ? "" : " · no change"}
                  </small>
                </td>
                <td>
                  {row.marketPriceMinor === null
                    ? "—"
                    : formatCzk(row.marketPriceMinor)}
                  {row.suggestionAboveMarket && (
                    <small className="flag-inline">
                      suggestion above market
                    </small>
                  )}
                </td>
                <td>
                  <div className="flag-list">
                    {row.flags.map((flag) => (
                      <span key={flag} className={`flag ${flag}`}>
                        {flag === "above_market"
                          ? "Current above market"
                          : flagLabels[flag] || flag}
                      </span>
                    ))}
                    {!row.applicable && !row.flags.length && (
                      <span className="flag">Not applicable</span>
                    )}
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {!preview && !error && <p className="admin-empty">Loading preview…</p>}
        {preview && !preview.rows.length && (
          <p className="admin-empty">
            No variant here has a suggested price yet. A suggestion needs a
            matched supplier offer checked recently, with an exchange rate, and
            a pricing rule for its cost.
          </p>
        )}
        {preview && preview.rows.length > 0 && !rows.length && (
          <p className="admin-empty">
            No rows match this filter. Variants without a suggestion aren&apos;t
            listed here; open the product to see their pricing panel.
          </p>
        )}
      </div>
    </>
  );
}
