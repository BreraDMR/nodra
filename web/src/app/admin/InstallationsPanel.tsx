"use client";
import { useCallback, useEffect, useState } from "react";
import { Chip } from "./OrderPanel";
import {
  bookingStatusLabels,
  bookingStatuses,
  type AdminInstallation,
} from "./installations";
import {
  errorText,
  formatCzk,
  formatDateTime,
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

type OpenForm = {
  order: OrderRow | null;
  from: string;
  to: string;
  note: string;
};
const freshOpen = (): OpenForm => ({ order: null, from: "", to: "", note: "" });

// the small form under an expanded booking
type ActionForm = {
  id: string;
  action: "reschedule" | "confirm" | "complete" | "cancel";
  from: string;
  to: string;
  text: string;
};
const freshAction = (id: string, action: ActionForm["action"]): ActionForm => ({
  id,
  action,
  from: "",
  to: "",
  text: "",
});

// datetime-local inputs need a minute-precision local stamp; the API takes any ISO instant
const localStamp = (value: string): string => value || "";

export function InstallationsPanel({
  send,
  onChanged,
}: {
  send: Send;
  onChanged: () => void;
}) {
  const [list, setList] = useState<Paged<AdminInstallation> | null>(null);
  const [listError, setListError] = useState("");
  const [status, setStatus] = useState("");
  const [page, setPage] = useState(1);
  const [revision, setRevision] = useState(0);

  const [open, setOpen] = useState<AdminInstallation | null>(null);
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
    send<Paged<AdminInstallation>>(`/api/admin/installations?${query}`)
      .then((result) => {
        if (!live) return;
        setList(result);
        setListError("");
      })
      .catch(
        (e) =>
          live && setListError(errorText(e, "Could not load installations")),
      );
    return () => {
      live = false;
    };
  }, [send, status, page, revision]);

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

  async function bookInstallation() {
    if (!openForm.order || !openForm.from || !openForm.to) return;
    setBusy(true);
    setError("");
    try {
      const booking = await send<AdminInstallation>(
        "/api/admin/installations",
        "POST",
        {
          orderId: openForm.order.id,
          from: new Date(openForm.from).toISOString(),
          to: new Date(openForm.to).toISOString(),
          note: openForm.note.trim() || null,
        },
      );
      setOpenForm(freshOpen);
      setOpen(booking);
      setForm(null);
      refresh();
      onChanged();
    } catch (e) {
      setError(errorText(e, "Could not book the installation"));
    } finally {
      setBusy(false);
    }
  }

  async function loadBooking(id: string) {
    setError("");
    try {
      const booking = await send<AdminInstallation>(
        `/api/admin/installations/${id}`,
      );
      setOpen(booking);
      return booking;
    } catch (e) {
      setError(errorText(e, "Could not load the booking"));
      return null;
    }
  }

  async function runAction() {
    if (!form || !open) return;
    setBusy(true);
    setError("");
    const body: Record<string, unknown> = {};
    if (form.action === "reschedule") {
      body.from = new Date(form.from).toISOString();
      body.to = new Date(form.to).toISOString();
    }
    if (form.action === "confirm") {
      body.compatibilityNote = form.text.trim();
    }
    if (form.action === "complete" || form.action === "cancel") {
      body.reason = form.text.trim();
    }
    try {
      const booking = await send<AdminInstallation>(
        `/api/admin/installations/${form.id}/${form.action}`,
        "POST",
        body,
      );
      setOpen(booking);
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
    ? (list?.items.find((b) => b.id === open.id) ?? open)
    : null;

  return (
    <>
      <div className="admin-heading compact">
        <div>
          <p className="eyebrow">AFTER-SALES / INSTALLATIONS</p>
          <h1>
            Installations<span>.</span>
          </h1>
          <p>
            Evening installations in Prague on the orders: the preliminary
            window agreed with the customer, the work window fixed at confirm,
            at most a couple of bookings an evening and enough road between
            them.
          </p>
        </div>
      </div>

      <section className="admin-panel purchase-section">
        <div className="panel-head">
          <div>
            <p className="eyebrow">NEW BOOKING</p>
            <h2>Book an installation</h2>
          </div>
        </div>
        {openForm.order ? (
          <form
            className="admin-product-search claim-open-form"
            onSubmit={(e) => {
              e.preventDefault();
              void bookInstallation();
            }}
          >
            <p>
              <strong>{openForm.order.reference}</strong> ·{" "}
              {openForm.order.customerName}
              <button
                type="button"
                className="admin-link"
                onClick={() => setOpenForm(freshOpen)}
              >
                pick another order
              </button>
            </p>
            <div className="orders-filters">
              <label className="admin-field">
                From
                <input
                  type="datetime-local"
                  required
                  value={openForm.from}
                  onChange={(e) =>
                    setOpenForm({
                      ...openForm,
                      from: localStamp(e.target.value),
                    })
                  }
                />
              </label>
              <label className="admin-field">
                To
                <input
                  type="datetime-local"
                  required
                  value={openForm.to}
                  onChange={(e) =>
                    setOpenForm({ ...openForm, to: localStamp(e.target.value) })
                  }
                />
              </label>
              <label className="admin-field">
                What the customer asked for
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
              {busy ? "Booking…" : "Book installation"}
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
              aria-label="Search the order to book the installation on"
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
                      onClick={() => {
                        setOrderHits([]);
                        setOrderQuery("");
                        setOpenForm({
                          order: row,
                          from: "",
                          to: "",
                          note: "",
                        });
                      }}
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
            <h2>Bookings</h2>
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
                {bookingStatuses.map((s) => (
                  <option key={s} value={s}>
                    {bookingStatusLabels[s]}
                  </option>
                ))}
              </select>
            </label>
          </div>
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
                <th>BOOKED</th>
                <th>ORDER</th>
                <th>WINDOW</th>
                <th>WORK</th>
                <th>WORKS</th>
                <th>STATUS</th>
              </tr>
            </thead>
            <tbody>
              {list?.items.map((b) => (
                <tr key={b.id}>
                  <td>{formatDateTime(b.createdAt)}</td>
                  <td>
                    <strong>{b.orderReference}</strong>
                    <small>{b.customerName}</small>
                  </td>
                  <td>
                    {formatDateTime(b.from)}
                    <small>– {formatDateTime(b.to)}</small>
                  </td>
                  <td>
                    {b.workFrom ? (
                      <>
                        {formatDateTime(b.workFrom)}
                        <small>– {formatDateTime(b.workTo)}</small>
                      </>
                    ) : (
                      "not confirmed"
                    )}
                  </td>
                  <td>
                    {b.works.length
                      ? `${b.workNames.join(", ")}${
                          b.priceMinor === null
                            ? ""
                            : ` · ${formatCzk(b.priceMinor)}`
                        }`
                      : "—"}
                  </td>
                  <td>
                    <Chip value={b.status} />
                    <button
                      type="button"
                      className="admin-link"
                      onClick={() => void loadBooking(b.id)}
                    >
                      {open?.id === b.id ? "Close" : "Open"}
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          {list && !list.items.length && (
            <p className="admin-empty">
              No installations booked yet. Book one on the order that needs the
              work.
            </p>
          )}
          {!list && !listError && <p className="admin-empty">Loading…</p>}
        </div>
        {list && list.pages > 1 && (
          <div className="admin-pagination">
            <span>
              {list.total} bookings · Page {list.page} of {list.pages}
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
                <p className="eyebrow">BOOKING / {shown.orderReference}</p>
                <h2>
                  {bookingStatusLabels[shown.status]} installation ·{" "}
                  {formatDateTime(shown.from)}
                </h2>
              </div>
              <button type="button" onClick={() => setOpen(null)}>
                Close ×
              </button>
            </div>
            <dl className="claim-facts">
              <div>
                <dt>Customer</dt>
                <dd>{shown.customerName}</dd>
              </div>
              <div>
                <dt>Preliminary window</dt>
                <dd>
                  {formatDateTime(shown.from)} – {formatDateTime(shown.to)}
                </dd>
              </div>
              <div>
                <dt>Work window</dt>
                <dd>
                  {shown.workFrom
                    ? `${formatDateTime(shown.workFrom)} – ${formatDateTime(shown.workTo)}`
                    : "not confirmed yet"}
                </dd>
              </div>
              <div>
                <dt>Works</dt>
                <dd>
                  {shown.works.length
                    ? `${shown.workNames.join(", ")}${
                        shown.priceMinor === null
                          ? " · no price agreed yet"
                          : ` · ${formatCzk(shown.priceMinor)}`
                      }`
                    : "agreed with the customer before confirm"}
                </dd>
              </div>
              <div>
                <dt>Asked for</dt>
                <dd>{shown.note || "—"}</dd>
              </div>
              {shown.compatibilityNote && (
                <div>
                  <dt>Checked before confirm</dt>
                  <dd>{shown.compatibilityNote}</dd>
                </div>
              )}
              {shown.resultNote && (
                <div>
                  <dt>Result</dt>
                  <dd>{shown.resultNote}</dd>
                </div>
              )}
              {shown.cancelledReason && (
                <div>
                  <dt>Cancelled</dt>
                  <dd>{shown.cancelledReason}</dd>
                </div>
              )}
              <div>
                <dt>Booked</dt>
                <dd>
                  {formatDateTime(shown.createdAt)} by {shown.createdBy}
                </dd>
              </div>
            </dl>
            <div className="claim-actions">
              {["planned", "confirmed"].includes(shown.status) && (
                <button
                  type="button"
                  onClick={() => setForm(freshAction(shown.id, "reschedule"))}
                >
                  Move the window
                </button>
              )}
              {shown.status === "planned" && (
                <button
                  type="button"
                  onClick={() => setForm(freshAction(shown.id, "confirm"))}
                >
                  Confirm the work
                </button>
              )}
              {shown.status === "confirmed" && (
                <button
                  type="button"
                  onClick={() => setForm(freshAction(shown.id, "complete"))}
                >
                  Close as done
                </button>
              )}
              {["planned", "confirmed"].includes(shown.status) && (
                <button
                  type="button"
                  onClick={() => setForm(freshAction(shown.id, "cancel"))}
                >
                  Call off
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
                {form.action === "reschedule" && (
                  <>
                    <label className="admin-field">
                      New from
                      <input
                        type="datetime-local"
                        required
                        value={form.from}
                        onChange={(e) =>
                          setForm({ ...form, from: e.target.value })
                        }
                      />
                    </label>
                    <label className="admin-field">
                      New to
                      <input
                        type="datetime-local"
                        required
                        value={form.to}
                        onChange={(e) =>
                          setForm({ ...form, to: e.target.value })
                        }
                      />
                    </label>
                  </>
                )}
                {form.action === "confirm" && (
                  <label className="admin-field">
                    What was checked with the customer
                    <input
                      type="text"
                      required
                      maxLength={500}
                      value={form.text}
                      onChange={(e) =>
                        setForm({ ...form, text: e.target.value })
                      }
                    />
                  </label>
                )}
                {(form.action === "complete" || form.action === "cancel") && (
                  <label className="admin-field">
                    {form.action === "complete" ? "Result" : "Reason"}
                    <input
                      type="text"
                      required
                      maxLength={500}
                      value={form.text}
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
              </form>
            )}
          </div>
        )}
      </section>
    </>
  );
}
