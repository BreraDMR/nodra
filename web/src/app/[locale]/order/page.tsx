"use client";
import { useEffect, useState } from "react";
import { useParams, useSearchParams } from "next/navigation";
import Link from "next/link";
import { isDemoMode } from "@/lib/demo";
import {
  channels,
  dateText,
  expectedText,
  orderCopy,
  payOnReceiptText,
  pickupNoteText,
  windowText,
} from "@/lib/order";
import {
  copy,
  isLocale,
  money,
  type ContactChannel,
  type OrderReceipt,
} from "@/lib/shop";

// Fallback for a receipt without contactChannel: checkout leaves it in this tab
function storedChannel(reference: string): ContactChannel | null {
  try {
    const saved = JSON.parse(
      sessionStorage.getItem("nodra-last-order") || "null",
    ) as { reference?: string; contactChannel?: ContactChannel } | null;
    return saved?.reference === reference &&
      saved.contactChannel &&
      channels.includes(saved.contactChannel)
      ? saved.contactChannel
      : null;
  } catch {
    return null;
  }
}

export default function OrderPage() {
  const { locale: raw } = useParams<{ locale: string }>();
  const locale = isLocale(raw) ? raw : "en";
  const t = copy[locale];
  const o = orderCopy[locale];
  const params = useSearchParams();
  const reference = params.get("reference");
  const token = params.get("token");
  const [loaded, setLoaded] = useState<{
    receipt: OrderReceipt;
    channel: ContactChannel | null;
  } | null>(null);
  const [failed, setFailed] = useState(false);
  useEffect(() => {
    if (!reference || !token) return;
    const controller = new AbortController();
    fetch(
      `/api/orders/${encodeURIComponent(reference)}?token=${encodeURIComponent(token)}`,
      { cache: "no-store", signal: controller.signal },
    )
      .then((r) => {
        if (!r.ok) throw Error(r.statusText);
        return r.json() as Promise<OrderReceipt>;
      })
      .then((receipt) =>
        setLoaded({
          receipt,
          channel: receipt.contactChannel ?? storedChannel(receipt.reference),
        }),
      )
      .catch(() => {
        if (!controller.signal.aborted) setFailed(true);
      });
    return () => controller.abort();
  }, [reference, token]);

  const notFound = !reference || !token || failed;
  const receipt = loaded?.receipt;
  const currency = receipt?.total.currency ?? "CZK";
  // once a line's shipment is past "planned", its status says more than a lead time
  const lineShipmentStatus = (number: number) => {
    const shipment = receipt?.shipments.find((s) => s.number === number);
    return shipment && shipment.status !== "planned"
      ? o.shipmentStatuses[shipment.status]
      : null;
  };
  const statusText =
    receipt &&
    o.statusText[receipt.status].replace(
      "{via}",
      o.via[loaded?.channel ?? "unknown"],
    );

  return (
    <main className="confirmation-page">
      <p className="eyebrow">NODRA / {t.confirmationLabel}</p>
      {notFound ? (
        <h1 role="alert">{t.orderNotFound}</h1>
      ) : !receipt ? (
        <p aria-live="polite">…</p>
      ) : (
        <>
          <div className="confirmation-icon">
            {receipt.status === "cancelled" ? "×" : "✓"}
          </div>
          <h1>
            {receipt.status === "requested"
              ? t.thankYou
              : o.statuses[receipt.status]}
          </h1>
          <p className="order-status-text">
            {statusText}
            {(receipt.status === "requested" ||
              receipt.status === "confirmed") &&
              ` ${payOnReceiptText(receipt.paymentMethods, locale)}`}
          </p>
          {isDemoMode && (
            <p className="demo-order-note" role="note">
              {t.demoOrder}
            </p>
          )}
          <div className="receipt">
            <div>
              <span>{t.order}</span>
              <strong>{receipt.reference}</strong>
            </div>
            <div>
              <span>{o.orderStatus}</span>
              <strong>{o.statuses[receipt.status]}</strong>
            </div>
            <div>
              <span>{o.payment}</span>
              <strong>{o.paymentStatuses[receipt.paymentStatus]}</strong>
            </div>
            <div>
              <span>{o.sent}</span>
              <strong>{dateText(receipt.createdAt, locale)}</strong>
            </div>
          </div>

          <section className="receipt-section">
            <h2>{o.itemsHeading}</h2>
            <div className="receipt">
              {receipt.items.map((x, i) => (
                <div
                  key={i}
                  className={x.state === "active" ? undefined : "is-inactive"}
                >
                  <span>
                    {x.name} · {x.variant} × {x.quantity}
                    <small>
                      {x.state !== "active"
                        ? o.lineStates[x.state]
                        : (lineShipmentStatus(x.shipment) ??
                          expectedText(
                            locale,
                            x.leadTimeMinDays,
                            x.leadTimeMaxDays,
                          ))}
                      {receipt.shipments.length > 1 &&
                        ` · ${o.shipment.replace("{n}", String(x.shipment))}`}
                    </small>
                  </span>
                  <strong>
                    {money({ amount: x.lineTotal, currency }, locale)}
                  </strong>
                </div>
              ))}
            </div>
          </section>

          <section className="receipt-section">
            <h2>{o.shipmentsHeading}</h2>
            <div className="receipt">
              {receipt.shipments.map((s) => (
                <div
                  key={s.number}
                  className={
                    s.status === "cancelled" ? "is-inactive" : undefined
                  }
                >
                  <span>
                    {o.shipment.replace("{n}", String(s.number))} ·{" "}
                    {o.methods[s.method]}
                    <small>
                      {o.shipmentStatuses[s.status]}
                      {s.scheduledFrom && s.scheduledTo
                        ? ` · ${windowText(s.scheduledFrom, s.scheduledTo, locale)}`
                        : s.status === "planned"
                          ? ` · ${expectedText(locale, s.leadTimeMinDays, s.leadTimeMaxDays)}`
                          : ""}
                    </small>
                    {s.method === "pickup_andel" && (
                      <small>
                        {pickupNoteText(receipt.pickupNote, locale)}
                      </small>
                    )}
                  </span>
                  <strong>
                    {s.fee.amount > 0 ? money(s.fee, locale) : o.free}
                  </strong>
                </div>
              ))}
            </div>
          </section>

          <section className="receipt-section">
            <h2>{o.totalsHeading}</h2>
            <div className="receipt">
              <div>
                <span>{t.subtotal}</span>
                <strong>{money(receipt.subtotal, locale)}</strong>
              </div>
              <div>
                <span>{t.shipping}</span>
                <strong>{money(receipt.shipping, locale)}</strong>
              </div>
              <div className="receipt-total">
                <span>{t.total}</span>
                <strong>{money(receipt.total, locale)}</strong>
              </div>
              <div>
                <span>{o.paid}</span>
                <strong>{money(receipt.paid, locale)}</strong>
              </div>
              <div>
                <span>{o.amountDue}</span>
                <strong>{money(receipt.amountDue, locale)}</strong>
              </div>
            </div>
          </section>
        </>
      )}
      {/* "an order is a request, we'll confirm…" only fits until it's confirmed */}
      {(!receipt || receipt.status === "requested") && <p>{t.demo}</p>}
      <Link className="button button-dark" href={`/${locale}/shop`}>
        {t.back} ↗
      </Link>
    </main>
  );
}
