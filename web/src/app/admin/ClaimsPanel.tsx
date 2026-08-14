"use client";
import { useCallback, useEffect, useState } from "react";
import { Chip } from "./OrderPanel";
import {
  claimKinds,
  claimKindLabels,
  claimResolutionLabels,
  claimResolutions,
  claimStatusLabels,
  claimStatuses,
  todayInPrague,
  type AdminClaim,
  type ClaimKind,
  type ClaimResolution,
} from "./claims";
import {
  errorText,
  formatCzk,
  formatDateTime,
  formatDay,
  type Paged,
  type Send,
} from "./shared";

// GET /api/admin/orders rows carry what the picker needs
type OrderRow = {
  id: string;
  reference: string;
  customerName: string;
  createdAt: string;
};
// the part of GET /api/admin/orders/{id} the open form reads
type OrderDetail = {
  id: string;
  reference: string;
  items: { id: string; sku: string; name: string; variant: string }[];
};

type OpenForm = {
  order: OrderDetail | null;
  itemId: string;
  kind: ClaimKind;
  contactedOn: string;
  note: string;
};
const freshOpen = (): OpenForm => ({
  order: null,
  itemId: "",
  kind: "return",
  contactedOn: todayInPrague(),
  note: "",
});

// the small form under an expanded claim
type ActionForm = {
  id: string;
  action: "wait" | "accept" | "reject" | "resolve";
  text: string;
  amount: string;
  resolution: ClaimResolution;
};
const freshAction = (id: string, action: ActionForm["action"]): ActionForm => ({
  id,
  action,
  text: "",
  amount: "",
  resolution: "refund",
});

export function ClaimsPanel({
  send,
  onChanged,
}: {
  send: Send;
  // dashboard counts follow a new claim
  onChanged: () => void;
}) {
  const [list, setList] = useState<Paged<AdminClaim> | null>(null);
  const [listError, setListError] = useState("");
  const [status, setStatus] = useState("");
  const [kind, setKind] = useState("");
  const [page, setPage] = useState(1);
  const [revision, setRevision] = useState(0);

  const [searchDraft, setSearchDraft] = useState("");
  const [search, setSearch] = useState("");

  const [open, setOpen] = useState<AdminClaim | null>(null);
  const [form, setForm] = useState<ActionForm | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  const [orderQuery, setOrderQuery] = useState("");
  const [orderHits, setOrderHits] = useState<OrderRow[]>([]);
  const [openForm, setOpenForm] = useState<OpenForm>(freshOpen);
  const refresh = useCallback(() => setRevision((n) => n + 1), []);

  useEffect(() => {
    let live = true;
    const query = new URLSearchParams({ page: String(page) });
    if (status) query.set("status", status);
    if (kind) query.set("kind", kind);
    if (search) query.set("q", search);
    send<Paged<AdminClaim>>(`/api/admin/claims?${query}`)
      .then((result) => {
        if (!live) return;
        setList(result);
        setListError("");
      })
      .catch(
        (e) => live && setListError(errorText(e, "Could not load claims")),
      );
    return () => {
      live = false;
    };
  }, [send, status, kind, page, search, revision]);

  async function loadClaim(id: string) {
    setError("");
    try {
      const claim = await send<AdminClaim>(`/api/admin/claims/${id}`);
      setOpen(claim);
      return claim;
    } catch (e) {
      setError(errorText(e, "Could not load the claim"));
      return null;
    }
  }

  async function searchOrders(query: string) {
    setError("");
    try {
      const result = await send<Paged<OrderRow>>(
        `/api/admin/orders?q=${encodeURIComponent(query)}&page=1`,
      );
      setOrderHits(result.items);
    } catch (e) {
      setError(errorText(e, "Could not search orders"));
    }
  }

  async function pickOrder(row: OrderRow) {
    setOrderHits([]);
    setOrderQuery("");
    setError("");
    try {
      const detail = await send<OrderDetail>(`/api/admin/orders/${row.id}`);
      setOpenForm({ ...freshOpen(), order: detail });
    } catch (e) {
      setError(errorText(e, "Could not load the order"));
    }
  }

  async function openClaim() {
    if (!openForm.order) return;
    setBusy(true);
    setError("");
    try {
      const claim = await send<AdminClaim>("/api/admin/claims", "POST", {
        orderId: openForm.order.id,
        itemId: openForm.itemId || null,
        kind: openForm.kind,
        contactedOn: openForm.contactedOn,
        note: openForm.note.trim() || null,
      });
      setOpenForm(freshOpen());
      setOpen(claim);
      setForm(null);
      refresh();
      onChanged();
    } catch (e) {
      setError(errorText(e, "Could not register the claim"));
    } finally {
      setBusy(false);
    }
  }

  async function runAction() {
    if (!form || !open) return;
    setBusy(true);
    setError("");
    const body: Record<string, unknown> = {};
    if (form.action === "wait" || form.action === "accept") {
      body.note = form.text.trim() || null;
    }
    if (form.action === "accept") {
      const kc = Number.parseFloat(form.amount.replace(",", "."));
      body.refundAmountMinor =
        form.amount.trim() === "" || Number.isNaN(kc)
          ? null
          : Math.round(kc * 100);
    }
    if (form.action === "reject") {
      body.reason = form.text.trim();
    }
    if (form.action === "resolve") {
      body.resolution = form.resolution;
      body.note = form.text.trim() || null;
    }
    try {
      const claim = await send<AdminClaim>(
        `/api/admin/claims/${form.id}/${form.action}`,
        "POST",
        body,
      );
      setOpen(claim);
      setForm(null);
      refresh();
      onChanged();
    } catch (e) {
      setError(errorText(e, "The action was refused"));
    } finally {
      setBusy(false);
    }
  }

  const shown = open
    ? (list?.items.find((c) => c.id === open.id) ?? open)
    : null;

  return (
    <>
      <div className="admin-heading compact">
        <div>
          <p className="eyebrow">AFTER-SALES / REGISTRY</p>
          <h1>
            Claims<span>.</span>
          </h1>
          <p>
            Returns and warranty cases as their own records: the window, the
            money, the outcome. The claim never changes the order — line states
            and refunds stay with the order actions.
          </p>
        </div>
      </div>

      <section className="admin-panel purchase-section">
        <div className="panel-head">
          <div>
            <p className="eyebrow">NEW CASE</p>
            <h2>Register a claim</h2>
          </div>
        </div>
        {openForm.order ? (
          <form
            className="admin-product-search claim-open-form"
            onSubmit={(e) => {
              e.preventDefault();
              void openClaim();
            }}
          >
            <p>
              <strong>{openForm.order.reference}</strong> ·{" "}
              {openForm.order.items.length} line
              {openForm.order.items.length === 1 ? "" : "s"}
              <button
                type="button"
                className="admin-link"
                onClick={() => setOpenForm(freshOpen())}
              >
                pick another order
              </button>
            </p>
            <div className="orders-filters">
              <label className="admin-field">
                Goods
                <select
                  value={openForm.itemId}
                  onChange={(e) =>
                    setOpenForm({ ...openForm, itemId: e.target.value })
                  }
                >
                  <option value="">Whole order</option>
                  {openForm.order.items.map((item) => (
                    <option key={item.id} value={item.id}>
                      {item.sku} · {item.name} / {item.variant}
                    </option>
                  ))}
                </select>
              </label>
              <label className="admin-field">
                Kind
                <select
                  value={openForm.kind}
                  onChange={(e) =>
                    setOpenForm({
                      ...openForm,
                      kind: e.target.value as ClaimKind,
                    })
                  }
                >
                  {claimKinds.map((k) => (
                    <option key={k} value={k}>
                      {claimKindLabels[k]}
                    </option>
                  ))}
                </select>
              </label>
              <label className="admin-field">
                Customer contacted on
                <input
                  type="date"
                  required
                  max={todayInPrague()}
                  value={openForm.contactedOn}
                  onChange={(e) =>
                    setOpenForm({ ...openForm, contactedOn: e.target.value })
                  }
                />
              </label>
              <label className="admin-field">
                What the customer reported
                <input
                  type="text"
                  value={openForm.note}
                  maxLength={500}
                  onChange={(e) =>
                    setOpenForm({ ...openForm, note: e.target.value })
                  }
                  placeholder="optional"
                />
              </label>
            </div>
            <button type="submit" className="admin-primary" disabled={busy}>
              {busy ? "Registering…" : "Open claim"}
            </button>
          </form>
        ) : (
          <form
            className="admin-product-search"
            onSubmit={(e) => {
              e.preventDefault();
              void searchOrders(orderQuery.trim());
            }}
          >
            <input
              type="search"
              value={orderQuery}
              onChange={(e) => setOrderQuery(e.target.value)}
              placeholder="Order reference, customer name or email"
              aria-label="Search the order to claim on"
              maxLength={80}
            />
            <button type="submit">Search orders ↗</button>
            {orderHits.length > 0 && (
              <ul className="claim-hits">
                {orderHits.slice(0, 6).map((row) => (
                  <li key={row.id}>
                    <button
                      type="button"
                      className="admin-link"
                      onClick={() => void pickOrder(row)}
                    >
                      {row.reference} · {row.customerName} ·{" "}
                      {formatDateTime(row.createdAt)}
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </form>
        )}
        {error && (
          <div className="admin-error" role="alert">
            {error}
            <button onClick={() => setError("")}>×</button>
          </div>
        )}
      </section>

      <section className="admin-panel purchase-section">
        <div className="panel-head">
          <div>
            <p className="eyebrow">RECORDED</p>
            <h2>Claims</h2>
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
              {claimStatuses.map((s) => (
                <option key={s} value={s}>
                  {claimStatusLabels[s]}
                </option>
              ))}
            </select>
          </label>
          <label className="admin-field">
            Kind
            <select
              value={kind}
              onChange={(e) => {
                setKind(e.target.value);
                setPage(1);
              }}
            >
              <option value="">Both kinds</option>
              {claimKinds.map((k) => (
                <option key={k} value={k}>
                  {claimKindLabels[k]}
                </option>
              ))}
            </select>
          </label>
          <label className="admin-field">
            Search
            <input
              type="search"
              value={searchDraft}
              maxLength={80}
              onChange={(e) => setSearchDraft(e.target.value)}
              placeholder="Claim number, order reference, customer"
              onKeyDown={(e) => {
                if (e.key === "Enter") {
                  e.preventDefault();
                  setPage(1);
                  setSearch(searchDraft.trim());
                }
              }}
            />
          </label>
          <button
            type="button"
            onClick={() => {
              setPage(1);
              setSearch(searchDraft.trim());
            }}
          >
            Search ↗
          </button>
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
                <th>OPENED</th>
                <th>CLAIM</th>
                <th>ORDER</th>
                <th>GOODS</th>
                <th>WINDOW END</th>
                <th>DUE</th>
                <th>MONEY</th>
                <th>STATUS</th>
              </tr>
            </thead>
            <tbody>
              {list?.items.map((c) => (
                <tr key={c.id}>
                  <td>{formatDateTime(c.openedAt)}</td>
                  <td>
                    <strong>{c.number}</strong>
                    <small>
                      {claimKindLabels[c.kind]}
                      {!c.onTime && " · contacted late"}
                    </small>
                  </td>
                  <td>
                    {c.orderReference}
                    <small>{c.customerName}</small>
                  </td>
                  <td>
                    {c.sku ? (
                      <>
                        <strong>{c.sku}</strong>
                        <small>
                          {c.productName} / {c.variantLabel}
                        </small>
                      </>
                    ) : (
                      "whole order"
                    )}
                  </td>
                  <td>
                    {formatDay(c.windowEnd)}
                    {!c.onTime && <small className="delayed-badge">late</small>}
                  </td>
                  <td>
                    {c.dueAt ? formatDay(c.dueAt) : "—"}
                    {c.overdue && (
                      <small className="delayed-badge">overdue</small>
                    )}
                  </td>
                  <td>
                    {c.refundAmountMinor === null
                      ? "—"
                      : formatCzk(c.refundAmountMinor)}
                  </td>
                  <td>
                    <Chip value={c.status} />
                    <button
                      type="button"
                      className="admin-link"
                      onClick={() => void loadClaim(c.id)}
                    >
                      {open?.id === c.id ? "Close" : "Open"}
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          {list && !list.items.length && (
            <p className="admin-empty">
              No claims registered. Cases arrive through the agreed channels;
              register them here.
            </p>
          )}
          {!list && !listError && (
            <p className="admin-empty">Loading claims…</p>
          )}
        </div>
        {list && list.pages > 1 && (
          <div className="admin-pagination">
            <span>
              {list.total} claims · Page {list.page} of {list.pages}
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
        {shown && (
          <div className="claim-detail">
            <div className="panel-head">
              <div>
                <p className="eyebrow">CASE {shown.number}</p>
                <h2>
                  {claimKindLabels[shown.kind]} on {shown.orderReference}
                </h2>
              </div>
              <button type="button" onClick={() => setOpen(null)}>
                Close ×
              </button>
            </div>
            <dl className="claim-facts">
              <div>
                <dt>Status</dt>
                <dd>
                  <Chip value={shown.status} />{" "}
                  {claimStatusLabels[shown.status]}
                </dd>
              </div>
              <div>
                <dt>Customer</dt>
                <dd>{shown.customerName}</dd>
              </div>
              <div>
                <dt>Goods</dt>
                <dd>
                  {shown.sku
                    ? `${shown.sku} · ${shown.productName} / ${shown.variantLabel}`
                    : "whole order"}
                </dd>
              </div>
              <div>
                <dt>Handed over</dt>
                <dd>{formatDay(shown.handoverDate)}</dd>
              </div>
              <div>
                <dt>Customer contacted</dt>
                <dd>{formatDay(shown.contactedOn)}</dd>
              </div>
              <div>
                <dt>Window ends</dt>
                <dd>
                  {formatDay(shown.windowEnd)}
                  {shown.onTime ? "" : " — the claim came in late"}
                </dd>
              </div>
              <div>
                <dt>Settle by</dt>
                <dd>
                  {shown.dueAt ? formatDay(shown.dueAt) : "—"}
                  {shown.overdue ? " — overdue" : ""}
                </dd>
              </div>
              <div>
                <dt>Agreed refund</dt>
                <dd>
                  {shown.refundAmountMinor === null
                    ? "not agreed yet"
                    : formatCzk(shown.refundAmountMinor)}
                </dd>
              </div>
              <div>
                <dt>Reported</dt>
                <dd>{shown.note || "—"}</dd>
              </div>
              {shown.resolutionNote && (
                <div>
                  <dt>Decision</dt>
                  <dd>
                    {shown.resolution
                      ? `${claimResolutionLabels[shown.resolution]}: `
                      : ""}
                    {shown.resolutionNote}
                  </dd>
                </div>
              )}
              <div>
                <dt>Opened</dt>
                <dd>
                  {formatDateTime(shown.openedAt)} by {shown.openedBy}
                </dd>
              </div>
            </dl>
            <div className="claim-actions">
              {shown.status === "open" && (
                <button
                  type="button"
                  onClick={() => setForm(freshAction(shown.id, "wait"))}
                >
                  Wait for the customer
                </button>
              )}
              {["open", "waiting", "accepted"].includes(shown.status) && (
                <button
                  type="button"
                  onClick={() => setForm(freshAction(shown.id, "accept"))}
                >
                  {shown.status === "accepted"
                    ? "Correct the agreed refund"
                    : "Accept"}
                </button>
              )}
              {["open", "waiting", "accepted"].includes(shown.status) && (
                <button
                  type="button"
                  onClick={() => setForm(freshAction(shown.id, "reject"))}
                >
                  Reject
                </button>
              )}
              {shown.status === "accepted" && (
                <button
                  type="button"
                  onClick={() => setForm(freshAction(shown.id, "resolve"))}
                >
                  Resolve
                </button>
              )}
            </div>
            {form && form.id === shown.id && (
              <form
                className="admin-product-search claim-action-form"
                onSubmit={(e) => {
                  e.preventDefault();
                  void runAction();
                }}
              >
                {form.action === "accept" && (
                  <label className="admin-field">
                    Agreed refund, Kč
                    <input
                      type="number"
                      min={0}
                      step={0.01}
                      value={form.amount}
                      onChange={(e) =>
                        setForm({ ...form, amount: e.target.value })
                      }
                      placeholder={
                        shown.refundAmountMinor === null
                          ? "0"
                          : (shown.refundAmountMinor / 100).toFixed(2)
                      }
                    />
                  </label>
                )}
                {form.action === "resolve" && (
                  <label className="admin-field">
                    Outcome
                    <select
                      value={form.resolution}
                      onChange={(e) =>
                        setForm({
                          ...form,
                          resolution: e.target.value as ClaimResolution,
                        })
                      }
                    >
                      {claimResolutions.map((r) => (
                        <option key={r} value={r}>
                          {claimResolutionLabels[r]}
                        </option>
                      ))}
                    </select>
                  </label>
                )}
                {form.action !== "accept" && (
                  <label className="admin-field">
                    {form.action === "reject" ? "Reason" : "Note"}
                    <input
                      type="text"
                      value={form.text}
                      maxLength={500}
                      required={form.action === "reject"}
                      onChange={(e) =>
                        setForm({ ...form, text: e.target.value })
                      }
                    />
                  </label>
                )}
                {form.action === "accept" && (
                  <label className="admin-field">
                    Note
                    <input
                      type="text"
                      value={form.text}
                      maxLength={500}
                      onChange={(e) =>
                        setForm({ ...form, text: e.target.value })
                      }
                    />
                  </label>
                )}
                <button type="submit" className="admin-primary" disabled={busy}>
                  {busy ? "Working…" : "Confirm"}
                </button>
                <button type="button" onClick={() => setForm(null)}>
                  Cancel
                </button>
                {form.action === "resolve" && form.resolution === "refund" && (
                  <small>
                    Refund money goes through the order ledger; the claim closes
                    only when refunds tagged with this claim cover the agreed
                    amount. Record the refund on the order and pick this claim
                    there.
                  </small>
                )}
              </form>
            )}
          </div>
        )}
      </section>
    </>
  );
}
