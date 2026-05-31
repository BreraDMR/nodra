"use client";
import { useCallback, useEffect, useState } from "react";
import { Chip, OrderPanel } from "./OrderPanel";
import {
  formatPrice,
  orderStatuses,
  paymentStatuses,
  statusLabel,
  type OrderRow,
} from "./orders";
import { errorText, formatDateTime, type Paged, type Send } from "./shared";

export function OrdersPanel({
  send,
  initialOrderId = null,
  onChanged,
}: {
  send: Send;
  // opened straight away, e.g. from the dashboard's recent orders
  initialOrderId?: string | null;
  onChanged: () => void;
}) {
  // "" = any status
  const [status, setStatus] = useState("");
  const [paymentStatus, setPaymentStatus] = useState("");
  const [page, setPage] = useState(1);
  const [data, setData] = useState<Paged<OrderRow> | null>(null);
  const [error, setError] = useState("");
  const [openId, setOpenId] = useState<string | null>(initialOrderId);
  // bumped after an order action so the row shows the new status
  const [revision, setRevision] = useState(0);

  const fetchPage = useCallback(
    (page: number, status: string, paymentStatus: string) => {
      const query = new URLSearchParams({ page: String(page) });
      if (status) query.set("status", status);
      if (paymentStatus) query.set("paymentStatus", paymentStatus);
      return send<Paged<OrderRow>>(`/api/admin/orders?${query}`);
    },
    [send],
  );
  useEffect(() => {
    let live = true;
    fetchPage(page, status, paymentStatus)
      .then((result) => {
        if (!live) return;
        setData(result);
        setError("");
      })
      .catch((e) => live && setError(errorText(e, "Could not load orders")));
    return () => {
      live = false;
    };
  }, [fetchPage, page, status, paymentStatus, revision]);

  const filtered = Boolean(status || paymentStatus);
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
