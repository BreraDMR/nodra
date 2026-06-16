"use client";
import { useCallback, useEffect, useState } from "react";
import { Chip, OrderPanel, QueueTags } from "./OrderPanel";
import {
  formatPrice,
  orderQueues,
  orderStatuses,
  paymentStatuses,
  queueLabels,
  statusLabel,
  type OrderQueue,
  type OrderRow,
} from "./orders";
import { errorText, formatDateTime, type Paged, type Send } from "./shared";

export function OrdersPanel({
  send,
  initialOrderId = null,
  initialQueue = "",
  onChanged,
}: {
  send: Send;
  // opened straight away, e.g. from the dashboard's recent orders
  initialOrderId?: string | null;
  // a dashboard tile opens the list filtered by its queue
  initialQueue?: OrderQueue | "";
  onChanged: () => void;
}) {
  // "" = any status
  const [status, setStatus] = useState("");
  const [paymentStatus, setPaymentStatus] = useState("");
  const [queue, setQueue] = useState<OrderQueue | "">(initialQueue);
  // what is typed, and what the list was asked for a moment later
  const [searchDraft, setSearchDraft] = useState("");
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [data, setData] = useState<Paged<OrderRow> | null>(null);
  const [error, setError] = useState("");
  const [openId, setOpenId] = useState<string | null>(initialOrderId);
  // bumped after an order action so the row shows the new status
  const [revision, setRevision] = useState(0);

  const fetchPage = useCallback(
    (
      page: number,
      status: string,
      paymentStatus: string,
      queue: string,
      search: string,
    ) => {
      const query = new URLSearchParams({ page: String(page) });
      if (status) query.set("status", status);
      if (paymentStatus) query.set("paymentStatus", paymentStatus);
      if (queue) query.set("queue", queue);
      if (search) query.set("q", search);
      return send<Paged<OrderRow>>(`/api/admin/orders?${query}`);
    },
    [send],
  );
  useEffect(() => {
    let live = true;
    fetchPage(page, status, paymentStatus, queue, search)
      .then((result) => {
        if (!live) return;
        setData(result);
        setError("");
      })
      .catch((e) => live && setError(errorText(e, "Could not load orders")));
    return () => {
      live = false;
    };
  }, [fetchPage, page, status, paymentStatus, queue, search, revision]);

  // ask the API only once typing stops for a moment
  useEffect(() => {
    const next = searchDraft.trim();
    if (next === search) return;
    const timer = setTimeout(() => {
      setSearch(next);
      setPage(1);
    }, 350);
    return () => clearTimeout(timer);
  }, [searchDraft, search]);

  const filtered = Boolean(status || paymentStatus || queue || search);
  return (
    <>
      <div className="admin-heading compact">
        <div>
          <p className="eyebrow">OPERATIONS / FULFILMENT</p>
          <h1>
            Orders<span>.</span>
          </h1>
          <p>From the request to a paid handover.</p>
        </div>
      </div>
      <div className="orders-filters">
        <label className="admin-field orders-search">
          Search
          <input
            type="search"
            value={searchDraft}
            maxLength={100}
            placeholder="Reference, name, email, phone or supplier ref"
            onChange={(e) => setSearchDraft(e.target.value)}
          />
        </label>
        <label className="admin-field">
          Work queue
          <select
            value={queue}
            onChange={(e) => {
              setQueue(e.target.value as OrderQueue | "");
              setPage(1);
            }}
          >
            <option value="">All queues</option>
            {orderQueues.map((q) => (
              <option key={q} value={q}>
                {queueLabels[q]}
              </option>
            ))}
          </select>
        </label>
        <label className="admin-field">
          Order status
          <select
            value={status}
            onChange={(e) => {
              setStatus(e.target.value);
              setPage(1);
            }}
          >
            <option value="">All statuses</option>
            {orderStatuses.map((s) => (
              <option key={s} value={s}>
                {statusLabel(s)}
              </option>
            ))}
          </select>
        </label>
        <label className="admin-field">
          Payment status
          <select
            value={paymentStatus}
            onChange={(e) => {
              setPaymentStatus(e.target.value);
              setPage(1);
            }}
          >
            <option value="">All payment statuses</option>
            {paymentStatuses.map((s) => (
              <option key={s} value={s}>
                {statusLabel(s)}
              </option>
            ))}
          </select>
        </label>
        {filtered && (
          <button
            type="button"
            className="admin-secondary"
            onClick={() => {
              setStatus("");
              setPaymentStatus("");
              setQueue("");
              setSearchDraft("");
              setSearch("");
              setPage(1);
            }}
          >
            Clear filters
          </button>
        )}
      </div>
      {error && (
        <div className="admin-error" role="alert">
          {error}
          <button onClick={() => setError("")}>×</button>
        </div>
      )}
      <div className="admin-table-wrap">
        <table className="admin-table">
          <thead>
            <tr>
              <th>REFERENCE</th>
              <th>CUSTOMER</th>
              <th>PHONE</th>
              <th>STATUS</th>
              <th>PAYMENT</th>
              <th>QUEUES</th>
              <th>TOTAL</th>
              <th>CREATED</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            {data?.items.map((o) => (
              <tr key={o.id}>
                <td>
                  <strong>{o.reference}</strong>
                  {o.delayed && <span className="delayed-badge">Delayed</span>}
                </td>
                <td>
                  <strong>{o.customerName}</strong>
                  <small>{o.email}</small>
                </td>
                <td>{o.phone || "—"}</td>
                <td>
                  <Chip value={o.status} />
                </td>
                <td>
                  <Chip value={o.paymentStatus} />
                </td>
                <td>
                  {/* delayed shows next to the reference already */}
                  <QueueTags queues={o.queues} delayed={false} />
                  {!o.queues.some((q) => q !== "delayed") && "—"}
                </td>
                <td>{formatPrice(o.total)}</td>
                <td>{formatDateTime(o.createdAt)}</td>
                <td>
                  <button
                    className="admin-link"
                    onClick={() => setOpenId(o.id)}
                  >
                    Open ↗
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {data && !data.items.length && (
          <p className="admin-empty">
            {filtered ? "No orders match these filters." : "No orders yet."}
          </p>
        )}
        {!data && !error && <p className="admin-empty">Loading orders…</p>}
      </div>
      {data && (
        <div className="admin-pagination">
          <span>
            {data.total} {data.total === 1 ? "order" : "orders"} · Page{" "}
            {data.page} of {data.pages}
          </span>
          <div>
            <button
              disabled={data.page <= 1}
              onClick={() => setPage(data.page - 1)}
            >
              ← Previous
            </button>
            <button
              disabled={data.page >= data.pages}
              onClick={() => setPage(data.page + 1)}
            >
              Next →
            </button>
          </div>
        </div>
      )}
      {openId && (
        <OrderPanel
          key={openId}
          orderId={openId}
          send={send}
          onClose={() => setOpenId(null)}
          onChanged={() => {
            setRevision((n) => n + 1);
            onChanged();
          }}
        />
      )}
    </>
  );
}
