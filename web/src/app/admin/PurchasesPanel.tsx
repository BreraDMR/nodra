"use client";
import { useCallback, useEffect, useState } from "react";
import { Chip, OrderPanel } from "./OrderPanel";
import { ReasonForm } from "./OrderActionForms";
import { formatPrice, statusLabel } from "./orders";
import { PurchaseForm } from "./PurchaseForm";
import {
  purchaseProblemText,
  purchaseStatuses,
  supplierLabel,
  type AdminPurchase,
  type PurchaseRow,
  type ToPurchase,
  type ToPurchaseGroup,
} from "./purchases";
import {
  errorText,
  formatCzk,
  formatDateTime,
  formatDay,
  formatMinor,
  formatRate,
  type Paged,
  type Send,
} from "./shared";

// lines picked for one purchase; only lines of one supplier group go together
type Selection = { group: string; ids: Set<string> };
const groupKey = (group: ToPurchaseGroup) => group.supplier ?? "";

export function PurchasesPanel({
  send,
  onChanged,
}: {
  send: Send;
  // dashboard counts follow a new purchase
  onChanged: () => void;
}) {
  const [toPurchase, setToPurchase] = useState<ToPurchase | null>(null);
  const [toPurchaseError, setToPurchaseError] = useState("");
  const [selection, setSelection] = useState<Selection | null>(null);
  const [creating, setCreating] = useState(false);
  const [status, setStatus] = useState("");
  const [page, setPage] = useState(1);
  const [list, setList] = useState<Paged<PurchaseRow> | null>(null);
  const [listError, setListError] = useState("");
  const [openPurchase, setOpenPurchase] = useState<string | null>(null);
  const [openOrder, setOpenOrder] = useState<string | null>(null);
  const [notice, setNotice] = useState("");
  // bumped when something changed, so both lists load again
  const [revision, setRevision] = useState(0);

  useEffect(() => {
    let live = true;
    send<ToPurchase>("/api/admin/to-purchase")
      .then((result) => {
        if (!live) return;
        setToPurchase(result);
        setToPurchaseError("");
        // keep only picked lines that are still there to buy
        setSelection((current) => {
          if (!current) return null;
          const group = result.groups.find(
            (g) => groupKey(g) === current.group,
          );
          const ids = new Set(
            group?.lines
              .map((l) => l.itemId)
              .filter((id) => current.ids.has(id)) ?? [],
          );
          return ids.size ? { group: current.group, ids } : null;
        });
      })
      .catch(
        (e) =>
          live &&
          setToPurchaseError(errorText(e, "Could not load lines to buy")),
      );
    return () => {
      live = false;
    };
  }, [send, revision]);

  useEffect(() => {
    let live = true;
    const query = new URLSearchParams({ page: String(page) });
    if (status) query.set("status", status);
    send<Paged<PurchaseRow>>(`/api/admin/purchases?${query}`)
      .then((result) => {
        if (!live) return;
        setList(result);
        setListError("");
      })
      .catch(
        (e) => live && setListError(errorText(e, "Could not load purchases")),
      );
    return () => {
      live = false;
    };
  }, [send, status, page, revision]);

  const refresh = useCallback(() => setRevision((n) => n + 1), []);

  // the form opens from its button only, never because the selection changed
  function toggle(group: ToPurchaseGroup, itemId: string) {
    const key = groupKey(group);
    setCreating(false);
    setSelection((current) => {
      const ids = new Set(current?.group === key ? current.ids : []);
      if (ids.has(itemId)) ids.delete(itemId);
      else ids.add(itemId);
      return ids.size ? { group: key, ids } : null;
    });
  }
  function toggleAll(group: ToPurchaseGroup) {
    const key = groupKey(group);
    setCreating(false);
    setSelection((current) =>
      current?.group === key && current.ids.size === group.lines.length
        ? null
        : { group: key, ids: new Set(group.lines.map((l) => l.itemId)) },
    );
  }

  const selectedGroup =
    selection &&
    toPurchase?.groups.find((g) => groupKey(g) === selection.group);
  const selectedLines =
    selectedGroup?.lines.filter((l) => selection?.ids.has(l.itemId)) ?? [];

  return (
    <>
      <div className="admin-heading compact">
        <div>
          <p className="eyebrow">OPERATIONS / PROCUREMENT</p>
          <h1>
            Purchases<span>.</span>
          </h1>
          <p>What to buy, by supplier, and what was bought.</p>
        </div>
      </div>
      {notice && (
        <div className="admin-success" role="status">
          ✓ {notice}
          <button onClick={() => setNotice("")}>×</button>
        </div>
      )}

      <section className="admin-panel purchase-section">
        <div className="panel-head">
          <div>
            <p className="eyebrow">TO PURCHASE</p>
            <h2>
              {toPurchase
                ? `${toPurchase.lineCount} ${toPurchase.lineCount === 1 ? "line" : "lines"} to buy`
                : "Lines to buy"}
            </h2>
          </div>
          <button type="button" onClick={refresh}>
            ↻ Reload
          </button>
        </div>
        <p className="variant-note purchase-intro">
          Lines to order of confirmed orders, grouped by the supplier of the
          offer they were priced from. Tick lines of one supplier, then create
          the purchase you placed on their site.
        </p>
        {toPurchaseError && (
          <div className="admin-error" role="alert">
            {toPurchaseError}
            <button onClick={() => setToPurchaseError("")}>×</button>
          </div>
        )}
        {!toPurchase && !toPurchaseError && (
          <p className="admin-empty">Loading lines to buy…</p>
        )}
        {toPurchase && !toPurchase.groups.length && (
          <p className="admin-empty">
            Nothing to buy: every confirmed order has its goods ordered.
          </p>
        )}
        {toPurchase?.groups.map((group) => {
          const key = groupKey(group);
          const mine = selection?.group === key ? selection.ids : null;
          const otherPicked = Boolean(selection && selection.group !== key);
          return (
            <div className="purchase-group" key={key || "none"}>
              <div className="purchase-group-head">
                <h3>
                  {supplierLabel(group.supplier)}
                  <small>
                    {group.lines.length}{" "}
                    {group.lines.length === 1 ? "line" : "lines"}
                    {group.supplier === null &&
                      " · no offer at checkout, pick the supplier when you buy"}
                  </small>
                </h3>
                <div className="order-form-actions">
                  <button
                    type="button"
                    className="admin-secondary"
                    disabled={otherPicked}
                    onClick={() => toggleAll(group)}
                  >
                    {mine && mine.size === group.lines.length
                      ? "Clear"
                      : "Select all"}
                  </button>
                  <button
                    type="button"
                    className="admin-primary"
                    disabled={!mine?.size}
                    onClick={() => setCreating(true)}
                  >
                    Create purchase
                    {mine?.size ? ` · ${mine.size}` : ""} ↗
                  </button>
                </div>
              </div>
              <div className="admin-table-wrap">
                <table className="admin-table compact-table">
                  <thead>
                    <tr>
                      <th aria-label="Select"></th>
                      <th>ORDER</th>
                      <th>LINE</th>
                      <th>QTY</th>
                      <th>OFFER</th>
                      <th>SNAPSHOT COST</th>
                      <th>PROMISED</th>
                    </tr>
                  </thead>
                  <tbody>
                    {group.lines.map((l) => (
                      <tr key={l.itemId}>
                        <td>
                          <input
                            type="checkbox"
                            aria-label={`Select ${l.sku} of ${l.orderReference}`}
                            title={
                              otherPicked
                                ? "One purchase takes the lines of one supplier. Clear the other selection first."
                                : undefined
                            }
                            disabled={otherPicked}
                            checked={Boolean(mine?.has(l.itemId))}
                            onChange={() => toggle(group, l.itemId)}
                          />
                        </td>
                        <td>
                          <button
                            type="button"
                            className="admin-link"
                            onClick={() => setOpenOrder(l.orderId)}
                          >
                            {l.orderReference} ↗
                          </button>
                          {l.confirmedAt && (
                            <small>
                              confirmed {formatDateTime(l.confirmedAt)}
                            </small>
                          )}
                        </td>
                        <td>
                          <strong>{l.sku}</strong>
                          <small>
                            {l.name} / {l.variant} · sells at{" "}
                            {formatPrice(l.unitPrice)}
                          </small>
                        </td>
                        <td>{l.quantity}</td>
                        <td>
                          {l.offer ? (
                            <>
                              {formatMinor(
                                l.offer.priceMinor,
                                l.offer.currency,
                              )}
                              {l.offer.inboundShippingMinor > 0 &&
                                ` + ${formatMinor(l.offer.inboundShippingMinor, l.offer.currency)}`}
                              <small>
                                {l.offer.seller || "no seller"} ·{" "}
                                <a
                                  href={l.offer.url}
                                  target="_blank"
                                  rel="noopener noreferrer"
                                >
                                  Open offer ↗
                                </a>
                              </small>
                            </>
                          ) : (
                            "—"
                          )}
                        </td>
                        <td>
                          {l.snapshotUnitCostCzkMinor === null
                            ? "—"
                            : formatCzk(l.snapshotUnitCostCzkMinor)}
                        </td>
                        <td>
                          {l.promisedDate ? formatDay(l.promisedDate) : "—"}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          );
        })}
      </section>

      <section className="admin-panel purchase-section">
        <div className="panel-head">
          <div>
            <p className="eyebrow">RECORDED</p>
            <h2>Purchases</h2>
          </div>
        </div>
        <div className="orders-filters">
          <label className="admin-field">
            Status
            <select
              value={status}
              onChange={(e) => {
                setStatus(e.target.value);
                setPage(1);
              }}
            >
              <option value="">All statuses</option>
              {purchaseStatuses.map((s) => (
                <option key={s} value={s}>
                  {statusLabel(s)}
                </option>
              ))}
            </select>
          </label>
        </div>
        {listError && (
          <div className="admin-error" role="alert">
            {listError}
            <button onClick={() => setListError("")}>×</button>
          </div>
        )}
        <div className="admin-table-wrap">
          <table className="admin-table compact-table">
            <thead>
              <tr>
                <th>ORDERED</th>
                <th>SUPPLIER</th>
                <th>REFERENCE</th>
                <th>STATUS</th>
                <th>LINES</th>
                <th>GOODS + SHIPPING</th>
                <th>COST · CZK</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {list?.items.map((p) => (
                <tr key={p.id}>
                  <td>{formatDateTime(p.orderedAt)}</td>
                  <td>
                    <strong>{supplierLabel(p.supplier)}</strong>
                    {p.seller && <small>{p.seller}</small>}
                  </td>
                  <td>{p.reference}</td>
                  <td>
                    <Chip value={p.status} />
                  </td>
                  <td>{p.lineCount}</td>
                  <td>
                    {formatMinor(p.goodsMinor, p.currency)}
                    <small>
                      + {formatMinor(p.inboundShippingMinor, p.currency)}{" "}
                      shipping
                    </small>
                  </td>
                  <td>{formatCzk(p.costCzkMinor)}</td>
                  <td>
                    <button
                      className="admin-link"
                      onClick={() => setOpenPurchase(p.id)}
                    >
                      Open ↗
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          {list && !list.items.length && (
            <p className="admin-empty">
              {status ? "No purchases with this status." : "No purchases yet."}
            </p>
          )}
          {!list && !listError && (
            <p className="admin-empty">Loading purchases…</p>
          )}
        </div>
        {list && list.pages > 1 && (
          <div className="admin-pagination">
            <span>
              {list.total} purchases · Page {list.page} of {list.pages}
            </span>
            <div>
              <button
                disabled={list.page <= 1}
                onClick={() => setPage(list.page - 1)}
              >
                ← Previous
              </button>
              <button
                disabled={list.page >= list.pages}
                onClick={() => setPage(list.page + 1)}
              >
                Next →
              </button>
            </div>
          </div>
        )}
      </section>

      {creating && selectedGroup && selectedLines.length > 0 && (
        <div
          className="admin-modal-backdrop"
          onMouseDown={(e) => {
            if (e.target === e.currentTarget) setCreating(false);
          }}
        >
          <div className="admin-modal order-modal">
            <div className="modal-header">
              <div>
                <p className="eyebrow">PROCUREMENT / NEW PURCHASE</p>
                <h2>{supplierLabel(selectedGroup.supplier)}</h2>
              </div>
              <button onClick={() => setCreating(false)} aria-label="Close">
                ×
              </button>
            </div>
            {/* keyed by the group, so what was typed survives a reload that drops a
                line nobody can buy any more */}
            <PurchaseForm
              key={selectedGroup.supplier ?? ""}
              supplier={selectedGroup.supplier}
              lines={selectedLines}
              send={send}
              onCreated={(purchase) => {
                setCreating(false);
                setSelection(null);
                setNotice(
                  `Purchase ${purchase.reference} recorded: ${purchase.lines.length} ${purchase.lines.length === 1 ? "line" : "lines"} ordered.`,
                );
                setOpenPurchase(purchase.id);
                refresh();
                onChanged();
              }}
              onStale={refresh}
              onCancel={() => setCreating(false)}
            />
          </div>
        </div>
      )}
      {openPurchase && (
        <PurchasePanel
          key={openPurchase}
          purchaseId={openPurchase}
          revision={revision}
          send={send}
          onOpenOrder={setOpenOrder}
          onClose={() => setOpenPurchase(null)}
          onChanged={() => {
            refresh();
            onChanged();
          }}
        />
      )}
      {openOrder && (
        <OrderPanel
          key={openOrder}
          orderId={openOrder}
          send={send}
          onClose={() => setOpenOrder(null)}
          onChanged={() => {
            refresh();
            onChanged();
          }}
        />
      )}
    </>
  );
}

function PurchasePanel({
  purchaseId,
  revision,
  send,
  onOpenOrder,
  onClose,
  onChanged,
}: {
  purchaseId: string;
  // bumped when an order opened from here changed something
  revision: number;
  send: Send;
  onOpenOrder: (orderId: string) => void;
  onClose: () => void;
  onChanged: () => void;
}) {
  const [purchase, setPurchase] = useState<AdminPurchase | null>(null);
  const [loadError, setLoadError] = useState("");
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [busy, setBusy] = useState(false);
  const [cancelling, setCancelling] = useState(false);

  const fetchPurchase = useCallback(
    () => send<AdminPurchase>(`/api/admin/purchases/${purchaseId}`),
    [send, purchaseId],
  );
  useEffect(() => {
    let live = true;
    fetchPurchase()
      .then((result) => live && setPurchase(result))
      .catch(
        (e) => live && setLoadError(errorText(e, "Could not load purchase")),
      );
    return () => {
      live = false;
    };
  }, [fetchPurchase, revision]);

  const skuOf = (itemId: string) =>
    purchase?.lines.find((l) => l.itemId === itemId)?.sku ?? "a line";

  // receive has no body, cancel sends the reason; both answer with the purchase
  async function act(path: string, body: unknown, label: string) {
    setBusy(true);
    setError("");
    setNotice("");
    try {
      const updated = await send<AdminPurchase>(
        `/api/admin/purchases/${purchaseId}${path}`,
        "POST",
        body,
      );
      setPurchase(updated);
      setCancelling(false);
      setNotice(`${label}: done.`);
      onChanged();
      return true;
    } catch (e) {
      setError(purchaseProblemText(e, label, skuOf));
      // the state moved under us: show what is allowed now
      try {
        setPurchase(await fetchPurchase());
      } catch {
        // the error above says enough
      }
      return false;
    } finally {
      setBusy(false);
    }
  }

  const p = purchase;
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
            <p className="eyebrow">PROCUREMENT / PURCHASE</p>
            <h2>{p?.reference || "Purchase"}</h2>
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
        {!p &&
          (loadError ? (
            <p className="admin-error" role="alert">
              {loadError}
            </p>
          ) : (
            <p className="admin-empty">Loading purchase…</p>
          ))}
        {p && (
          <div className="order-panel">
            <div className="order-chips">
              <Chip value={p.status} />
              <span>
                {supplierLabel(p.supplier)}
                {p.seller ? ` · ${p.seller}` : ""} · ordered{" "}
                {formatDateTime(p.orderedAt)} by {p.createdBy}
              </span>
            </div>
            <div className="order-totals">
              <div>
                <span>Goods</span>
                <strong>{formatMinor(p.goodsMinor, p.currency)}</strong>
              </div>
              <div>
                <span>Inbound shipping</span>
                <strong>
                  {formatMinor(p.inboundShippingMinor, p.currency)}
                </strong>
              </div>
              <div>
                <span>Rate</span>
                <strong>
                  {p.currency === "CZK" ? "—" : formatRate(p.fxRateCzk)}
                </strong>
              </div>
              <div>
                <span>Cost · CZK</span>
                <strong>{formatCzk(p.costCzkMinor)}</strong>
              </div>
            </div>
            <dl className="pricing-rows">
              <dt>Currency</dt>
              <dd>
                {p.currency}
                {p.currency !== "CZK" &&
                  ` · ${formatRate(p.fxRateCzk)} CZK per ${p.currency}${p.fxRateDate ? ` of ${formatDay(p.fxRateDate)}` : ""}`}
              </dd>
              <dt>Received</dt>
              <dd>{formatDateTime(p.receivedAt)}</dd>
              {p.cancelledAt && (
                <>
                  <dt>Cancelled</dt>
                  <dd>
                    {formatDateTime(p.cancelledAt)}
                    {p.cancelReason && <small>reason: {p.cancelReason}</small>}
                  </dd>
                </>
              )}
              <dt>Note</dt>
              <dd>{p.note || "—"}</dd>
            </dl>
            {p.actions.length > 0 && (
              <div className="order-action-bar">
                {p.actions.includes("receive") && (
                  <button
                    type="button"
                    disabled={busy}
                    onClick={() => {
                      if (
                        confirm(
                          `Mark every still-ordered line of ${p.reference} received? Lines received one by one before stay as they are.`,
                        )
                      )
                        void act("/receive", undefined, "Receive");
                    }}
                  >
                    Receive
                  </button>
                )}
                {p.actions.includes("cancel") && (
                  <button
                    type="button"
                    className={`danger${cancelling ? " active" : ""}`}
                    disabled={busy}
                    aria-pressed={cancelling}
                    onClick={() => setCancelling((v) => !v)}
                  >
                    Cancel purchase
                  </button>
                )}
              </div>
            )}
            {cancelling && p.actions.includes("cancel") && (
              <ReasonForm
                title="Cancel purchase"
                path="/cancel"
                hint="Before anything arrived: its ordered lines go back to to-order without a supplier ref and lose the actual cost. Failed lines stay failed."
                confirmText={`Cancel purchase ${p.reference}? This can't be undone.`}
                busy={busy}
                onSubmit={(path, body, label) => act(path, body, label)}
                onCancel={() => setCancelling(false)}
              />
            )}

            <h3>Lines</h3>
            <div className="admin-table-wrap">
              <table className="admin-table compact-table">
                <thead>
                  <tr>
                    <th>ORDER</th>
                    <th>LINE</th>
                    <th>QTY</th>
                    <th>UNIT PRICE</th>
                    <th>SHIPPING SHARE</th>
                    <th>ACTUAL / SNAPSHOT</th>
                    <th>NOW</th>
                  </tr>
                </thead>
                <tbody>
                  {p.lines.map((l) => {
                    const diff =
                      l.snapshotUnitCostCzkMinor === null
                        ? null
                        : l.unitCostCzkMinor - l.snapshotUnitCostCzkMinor;
                    return (
                      <tr key={l.id}>
                        <td>
                          <button
                            type="button"
                            className="admin-link"
                            onClick={() => onOpenOrder(l.orderId)}
                          >
                            {l.orderReference} ↗
                          </button>
                        </td>
                        <td>
                          <strong>{l.sku}</strong>
                          <small>
                            {l.name} / {l.variant}
                          </small>
                        </td>
                        <td>{l.quantity}</td>
                        <td>{formatMinor(l.unitPriceMinor, p.currency)}</td>
                        <td>
                          {formatMinor(l.allocatedShippingMinor, p.currency)}
                        </td>
                        <td>
                          <b>{formatCzk(l.unitCostCzkMinor)}</b>
                          <small>
                            snapshot{" "}
                            {l.snapshotUnitCostCzkMinor === null
                              ? "—"
                              : formatCzk(l.snapshotUnitCostCzkMinor)}
                            {diff !== null &&
                              diff !== 0 &&
                              ` · ${diff > 0 ? "+" : "−"}${formatCzk(Math.abs(diff))}`}
                          </small>
                        </td>
                        <td>
                          <Chip value={l.procurementStatus} />
                          {l.lineState !== "active" && (
                            <Chip value={l.lineState} />
                          )}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
