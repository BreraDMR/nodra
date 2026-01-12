"use client";
import { useEffect, useState } from "react";
import { useParams, useSearchParams } from "next/navigation";
import Link from "next/link";
import { copy, isLocale, money, type Money } from "@/lib/shop";
type Receipt = {
  reference: string;
  status: string;
  items: {
    name: string;
    variant: string;
    quantity: number;
    lineTotal: number;
  }[];
  subtotal: Money;
  shipping: Money;
  total: Money;
};
export default function OrderPage() {
  const { locale: raw } = useParams<{ locale: string }>();
  const locale = isLocale(raw) ? raw : "en";
  const t = copy[locale];
  const params = useSearchParams();
  const reference = params.get("reference");
  const token = params.get("token");
  const [receipt, setReceipt] = useState<Receipt | null>(null);
  const [error, setError] = useState("");
  useEffect(() => {
    if (!reference || !token) return;
    fetch(
      `/api/orders/${encodeURIComponent(reference)}?token=${encodeURIComponent(token)}`,
    )
      .then((r) => {
        if (!r.ok) throw Error("Order not found");
        return r.json();
      })
      .then(setReceipt)
      .catch((e) => setError(e.message));
  }, [reference, token]);
  return (
    <main className="confirmation-page">
      <p className="eyebrow">NODRA / ORDER CONFIRMATION</p>
      <div className="confirmation-icon">✓</div>
      <h1>{t.thankYou}</h1>
      {error && <p role="alert">{error}</p>}
      {receipt && (
        <div className="receipt">
          <div>
            <span>{t.order}</span>
            <strong>{receipt.reference}</strong>
          </div>
          {receipt.items.map((x, i) => (
            <div key={i}>
              <span>
                {x.name} · {x.variant} × {x.quantity}
              </span>
              <strong>
                {money(
                  { amount: x.lineTotal, currency: receipt.total.currency },
                  locale,
                )}
              </strong>
            </div>
          ))}
          <div>
            <span>{t.shipping}</span>
            <strong>{money(receipt.shipping, locale)}</strong>
          </div>
          <div className="receipt-total">
            <span>{t.total}</span>
            <strong>{money(receipt.total, locale)}</strong>
          </div>
        </div>
      )}
      <p>{t.demo}</p>
      <Link className="button button-dark" href={`/${locale}/shop`}>
        {t.back} ↗
      </Link>
    </main>
  );
}
