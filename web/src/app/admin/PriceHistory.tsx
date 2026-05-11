"use client";
import { useEffect, useState } from "react";
import {
  errorText,
  formatCzk,
  formatMinor,
  parseApiDate,
  type Paged,
  type PriceChange,
  type Send,
} from "./shared";

const reasons: Record<PriceChange["reason"], string> = {
  manual: "Manual edit",
  reprice: "Suggestion applied",
  import: "Import",
};

function change(from: string | null, to: string) {
  // the very first price of a variant has nothing before it
  return from === null ? (
    <>
      <b>{to}</b>
      <small>first price</small>
    </>
  ) : (
    <>
      <b>{to}</b>
      <small>was {from}</small>
    </>
  );
}

// NODRA's own price changes for one variant, 30 per page, newest first
export function PriceHistory({
  variantId,
  send,
}: {
  variantId: string;
  send: Send;
}) {
  const [page, setPage] = useState(1);
  const [data, setData] = useState<Paged<PriceChange> | null>(null);
  const [error, setError] = useState("");

  useEffect(() => {
    let live = true;
    send<Paged<PriceChange>>(
      `/api/admin/variants/${variantId}/price-history?page=${page}`,
    )
      .then((result) => {
        if (!live) return;
        setData(result);
        setError("");
      })
      .catch((e) => live && setError(errorText(e, "Could not load history")));
    return () => {
      live = false;
    };
  }, [send, variantId, page]);

  if (error)
    return (
      <p className="admin-error" role="alert">
        {error}
      </p>
    );
  if (!data) return <p className="admin-empty">Loading price history…</p>;
  if (!data.total)
    return (
      <p className="admin-empty">
        No price changes recorded yet. Prices set before the history started
        aren&apos;t listed.
      </p>
    );
  return (
    <div className="price-history">
      <div className="admin-table-wrap">
        <table className="admin-table compact-table">
          <thead>
            <tr>
              <th>WHEN</th>
              <th>CZK</th>
              <th>EUR</th>
              <th>REASON</th>
            </tr>
          </thead>
          <tbody>
            {data.items.map((item) => (
              <tr key={item.id}>
                <td>
                  {parseApiDate(item.changedAt).toLocaleString("en-GB", {
                    dateStyle: "medium",
                    timeStyle: "short",
                  })}
                  <small>{item.changedBy}</small>
                </td>
                <td>
                  {change(
                    item.oldPriceCzk === null
                      ? null
                      : formatCzk(item.oldPriceCzk),
                    formatCzk(item.newPriceCzk),
                  )}
                </td>
                <td>
                  {change(
                    item.oldPriceEur === null
                      ? null
                      : formatMinor(item.oldPriceEur, "EUR"),
                    formatMinor(item.newPriceEur, "EUR"),
                  )}
                </td>
                <td>
                  <span className={`status reason-${item.reason}`}>
                    {reasons[item.reason] || item.reason}
                  </span>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {data.pages > 1 && (
        <div className="admin-pagination">
          <span>
            {data.total} changes · Page {data.page} of {data.pages}
          </span>
          <div>
            <button
              type="button"
              disabled={data.page <= 1}
              onClick={() => setPage(data.page - 1)}
            >
              ← Newer
            </button>
            <button
              type="button"
              disabled={data.page >= data.pages}
              onClick={() => setPage(data.page + 1)}
            >
              Older →
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
