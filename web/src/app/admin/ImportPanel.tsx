"use client";
import { useCallback, useEffect, useState } from "react";
import {
  ApiError,
  formatMinor,
  formatRate,
  type ImportChange,
  type ImportReport,
  type ImportRunPage,
  type ImportSetting,
  type Send,
} from "./shared";

type Props = {
  send: Send;
  csrfToken: string;
};

// Multipart upload of the feed file; JSON panels go through send() instead
async function uploadReport(
  url: string,
  file: File,
  runId: string | null,
  csrfToken: string,
): Promise<ImportReport> {
  const body = new FormData();
  body.append("file", file);
  if (runId) body.append("runId", runId);
  const r = await fetch(url, {
    method: "POST",
    headers: { "X-CSRF-Token": csrfToken, Accept: "application/json" },
    body,
    credentials: "same-origin",
  });
  const data = await r.json().catch(() => ({}));
  if (!r.ok)
    throw new ApiError(
      data?.message || `Request failed (${r.status})`,
      r.status,
      [],
      data && typeof data === "object" ? data : {},
    );
  return data as ImportReport;
}

function ChangeText({ change }: { change: ImportChange }) {
  const text = (value: string | number | null) =>
    value === null ? "—" : typeof value === "number" ? String(value) : value;
  return (
    <>
      <code>{change.field}</code> {text(change.old)} → <b>{text(change.new)}</b>
    </>
  );
}

// Upload → report → apply, plus the run journal and the supplier's import default.
// The feed supplier comes from the app.import.awin_supplier setting; the report carries it.
export default function ImportPanel({ send, csrfToken }: Props) {
  const [file, setFile] = useState<File | null>(null);
  const [report, setReport] = useState<ImportReport | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [message, setMessage] = useState("");
  const [journal, setJournal] = useState<ImportRunPage | null>(null);
  const [settings, setSettings] = useState<ImportSetting[]>([]);
  const [shipping, setShipping] = useState("");

  const loadJournal = useCallback(() => {
    let live = true;
    Promise.all([
      send<ImportRunPage>("/api/admin/imports/runs"),
      send<{ settings: ImportSetting[] }>("/api/admin/imports/settings"),
    ])
      .then(([page, list]) => {
        if (!live) return;
        setJournal(page);
        setSettings(list.settings);
      })
      .catch((e) => {
        if (live)
          setError(
            e instanceof Error
              ? e.message
              : "Could not load the import journal",
          );
      });
    return () => {
      live = false;
    };
  }, [send]);

  useEffect(() => loadJournal(), [loadJournal]);

  async function preview() {
    if (!file) return;
    setBusy(true);
    setError("");
    setMessage("");
    try {
      const r = await uploadReport(
        "/api/admin/imports/preview",
        file,
        null,
        csrfToken,
      );
      setReport(r);
      setMessage(
        `Previewed ${r.counts.totalRows} rows from ${r.fileName ?? "the feed"}. Nothing is written until you apply.`,
      );
      await loadJournal();
    } catch (e) {
      setError(e instanceof Error ? e.message : "Preview failed");
    } finally {
      setBusy(false);
    }
  }

  async function apply() {
    if (!file || !report) return;
    setBusy(true);
    setError("");
    setMessage("");
    try {
      await uploadReport(
        "/api/admin/imports/apply",
        file,
        report.runId,
        csrfToken,
      );
      setReport(null);
      setFile(null);
      setMessage(
        "Import applied. New cards are drafts — publish them after review.",
      );
      await loadJournal();
    } catch (e) {
      setError(e instanceof Error ? e.message : "Apply failed");
    } finally {
      setBusy(false);
    }
  }

  async function saveShipping(supplier: string) {
    setBusy(true);
    setError("");
    try {
      const minor =
        shipping.trim() === "" ? 0 : Number(shipping.replace(",", "."));
      if (!Number.isFinite(minor) || minor < 0)
        throw Error("Enter a non-negative amount");
      await send(`/api/admin/imports/settings/${supplier}`, "PUT", {
        inboundShippingMinor: Math.round(minor * 100),
      });
      setMessage(`Saved the import default for ${supplier}.`);
      await loadJournal();
    } catch (e) {
      setError(e instanceof Error ? e.message : "Could not save the setting");
    } finally {
      setBusy(false);
    }
  }

  const counts = report?.counts;
  const truncated = (list: unknown[], total: number) =>
    list.length < total ? (
      <small>
        Showing {list.length} of {total}.
      </small>
    ) : null;

  return (
    <div>
      {message && (
        <div className="admin-success" role="status">
          ✓ {message}
          <button onClick={() => setMessage("")}>×</button>
        </div>
      )}
      {error && (
        <div className="admin-error" role="alert">
          {error}
          <button onClick={() => setError("")}>×</button>
        </div>
      )}
      <div className="admin-heading compact">
        <div>
          <p className="eyebrow">CATALOG / FEED IMPORT</p>
          <h1>
            Import<span>.</span>
          </h1>
          <p>
            Upload the Awin feed, read the report, then apply. Nothing is
            deleted, drafts stay unpublished.
          </p>
        </div>
      </div>

      <section className="admin-panel">
        <div className="panel-head">
          <div>
            <p className="eyebrow">STEP 1 / FILE</p>
            <h2>Upload the feed</h2>
          </div>
        </div>
        <input
          type="file"
          accept=".csv,.gz,text/csv,application/gzip"
          onChange={(e) => setFile(e.target.files?.[0] ?? null)}
        />
        <div className="import-actions">
          <button
            className="admin-primary"
            disabled={busy || !file}
            onClick={preview}
          >
            Preview report ↗
          </button>
          {report && (
            <button className="admin-primary" disabled={busy} onClick={apply}>
              Apply import ↗
            </button>
          )}
        </div>
        <p className="variant-note">
          Up to 20 000 data rows per run. The report never writes: apply does,
          in one transaction, and refuses a file that changed since the preview.
        </p>
      </section>

      {report && counts && (
        <>
          <div className="metric-grid import-metrics">
            <div>
              <span>ROWS</span>
              <strong>{counts.totalRows}</strong>
            </div>
            <div>
              <span>NEW PRODUCTS</span>
              <strong>{counts.newProducts}</strong>
            </div>
            <div>
              <span>UPDATES</span>
              <strong>{counts.updates}</strong>
            </div>
            <div>
              <span>CONFLICTS</span>
              <strong>{counts.conflicts}</strong>
            </div>
            <div>
              <span>UNKNOWNS</span>
              <strong>{counts.unknowns}</strong>
            </div>
            <div>
              <span>ERRORS</span>
              <strong>{counts.errors}</strong>
            </div>
            <div>
              <span>MARGIN TOO LOW</span>
              <strong>{counts.suggestionsMarginTooLow}</strong>
            </div>
          </div>

          {report.newProducts.length > 0 && (
            <section className="admin-panel">
              <div className="panel-head">
                <div>
                  <p className="eyebrow">NEW PRODUCTS</p>
                  <h2>Would become drafts</h2>
                </div>
              </div>
              <div className="admin-table-wrap">
                <table className="admin-table">
                  <thead>
                    <tr>
                      <th>ROW</th>
                      <th>PRODUCT</th>
                      <th>BRAND</th>
                      <th>EAN / MPN</th>
                      <th>CATEGORY</th>
                      <th>FEED PRICE</th>
                    </tr>
                  </thead>
                  <tbody>
                    {report.newProducts.map((p) => (
                      <tr key={p.row}>
                        <td>{p.row}</td>
                        <td>{p.productName}</td>
                        <td>{p.brand ?? "—"}</td>
                        <td>
                          {p.ean ?? "—"} / {p.mpn ?? "—"}
                        </td>
                        <td>{p.category}</td>
                        <td>{formatMinor(p.priceMinor, p.currency)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              {truncated(report.newProducts, counts.newProducts)}
            </section>
          )}

          {report.updates.length > 0 && (
            <section className="admin-panel">
              <div className="panel-head">
                <div>
                  <p className="eyebrow">UPDATES</p>
                  <h2>Would change existing cards</h2>
                </div>
              </div>
              {report.updates.map((u) => (
                <div className="low-stock" key={`${u.row}-${u.variantId}`}>
                  <div>
                    <strong>{u.productName}</strong>
                    <small>{u.sku}</small>
                  </div>
                  <b>
                    {u.changes.map((c, i) => (
                      <span className="import-change" key={i}>
                        <ChangeText change={c} />
                      </span>
                    ))}
                  </b>
                </div>
              ))}
              {truncated(report.updates, counts.updates)}
            </section>
          )}

          {report.conflicts.length > 0 && (
            <section className="admin-panel">
              <div className="panel-head">
                <div>
                  <p className="eyebrow">CONFLICTS</p>
                  <h2>Need a human decision</h2>
                </div>
              </div>
              <ul className="import-list">
                {report.conflicts.map((c) => (
                  <li key={c.row}>
                    <b>Row {c.row}</b> — {c.message}
                  </li>
                ))}
              </ul>
              {truncated(report.conflicts, counts.conflicts)}
            </section>
          )}

          {report.unknowns.length > 0 && (
            <section className="admin-panel">
              <div className="panel-head">
                <div>
                  <p className="eyebrow">UNKNOWNS</p>
                  <h2>Values the catalogue doesn&apos;t know yet</h2>
                </div>
              </div>
              <ul className="import-list">
                {report.unknowns.map((u) => (
                  <li key={`${u.row}-${u.kind}-${u.message}`}>
                    <b>Row {u.row}</b> ({u.kind}) — {u.message}
                  </li>
                ))}
              </ul>
              {truncated(report.unknowns, counts.unknowns)}
            </section>
          )}

          {report.errors.length > 0 && (
            <section className="admin-panel">
              <div className="panel-head">
                <div>
                  <p className="eyebrow">ERRORS</p>
                  <h2>Broken rows, skipped</h2>
                </div>
              </div>
              <ul className="import-list">
                {report.errors.map((e) => (
                  <li key={`${e.row}-${e.message}`}>
                    <b>Row {e.row}</b> — {e.message}
                  </li>
                ))}
              </ul>
              {truncated(report.errors, counts.errors)}
            </section>
          )}

          {report.cost.rows.length > 0 && (
            <section className="admin-panel">
              <div className="panel-head">
                <div>
                  <p className="eyebrow">COST</p>
                  <h2>What the pricing rules would suggest</h2>
                </div>
              </div>
              <div className="admin-table-wrap">
                <table className="admin-table">
                  <thead>
                    <tr>
                      <th>PRODUCT</th>
                      <th>CATEGORY</th>
                      <th>FEED PRICE</th>
                      <th>RATE</th>
                      <th>LANDED COST</th>
                      <th>SUGGESTED</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                    {report.cost.rows.map((c) => (
                      <tr
                        key={`${c.variantId ?? "new"}-${c.productName}-${c.feedPriceMinor}`}
                      >
                        <td>{c.productName}</td>
                        <td>{c.categorySlug}</td>
                        <td>{formatMinor(c.feedPriceMinor, c.currency)}</td>
                        <td>
                          {c.fxRateCzk === null ? "—" : formatRate(c.fxRateCzk)}
                        </td>
                        <td>
                          {c.landedCostCzk === null
                            ? "—"
                            : formatMinor(c.landedCostCzk, "CZK")}
                        </td>
                        <td>
                          {c.suggestedPriceCzk === null
                            ? "—"
                            : formatMinor(c.suggestedPriceCzk, "CZK")}
                        </td>
                        <td>
                          {c.marginTooLow ? (
                            <span className="status draft">margin too low</span>
                          ) : (
                            ""
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <p className="variant-note">
                Suggestions are a preview — flagged rows are never repriced
                automatically.
              </p>
              {truncated(report.cost.rows, counts.rowsWithCost)}
            </section>
          )}
        </>
      )}

      <section className="admin-panel">
        <div className="panel-head">
          <div>
            <p className="eyebrow">SUPPLIER DEFAULTS</p>
            <h2>Import settings · bike_components</h2>
          </div>
        </div>
        <div className="import-settings">
          <label className="admin-field">
            Inbound shipping · per unit, feed currency
            <input
              inputMode="decimal"
              value={shipping}
              placeholder={String(
                (settings.find((s) => s.supplier === "bike_components")
                  ?.inboundShippingMinor ?? 0) / 100,
              )}
              onChange={(e) => setShipping(e.target.value)}
            />
          </label>
          <button
            disabled={busy}
            onClick={() => saveShipping("bike_components")}
          >
            Save default ↗
          </button>
        </div>
        <p className="variant-note">
          The default inbound shipping of the feed, set once per supplier —
          never per row.
        </p>
      </section>

      <section className="admin-panel">
        <div className="panel-head">
          <div>
            <p className="eyebrow">JOURNAL</p>
            <h2>Import runs</h2>
          </div>
        </div>
        {journal?.items.length ? (
          journal.items.map((run) => (
            <div className="low-stock" key={run.id}>
              <div>
                <strong>{run.fileName ?? "catalog seed"}</strong>
                <small>
                  {run.source} · {run.status} ·{" "}
                  {new Date(run.startedAt).toLocaleString("en-GB")} ·{" "}
                  {run.adminEmail ?? "console"}
                </small>
              </div>
              <b>
                {run.counts.newProducts} new · {run.counts.updates} updates
                {run.errorCount > 0 ? ` · ${run.errorCount} errors` : ""}
              </b>
            </div>
          ))
        ) : (
          <p className="admin-empty">No import runs yet.</p>
        )}
      </section>
    </div>
  );
}
