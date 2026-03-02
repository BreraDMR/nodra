"use client";
import { useEffect, useRef, useState } from "react";
import { useParams, useRouter } from "next/navigation";
import Link from "next/link";
import { useCartItems, writeCart } from "@/lib/cart";
import { copy, isLocale, money } from "@/lib/shop";
export default function Checkout() {
  const { locale: raw } = useParams<{ locale: string }>();
  const locale = isLocale(raw) ? raw : "en";
  const t = copy[locale];
  const router = useRouter();
  const items = useCartItems();
  const keyRef = useRef<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [account, setAccount] = useState<{
    name: string;
    email: string;
  } | null>(null);
  useEffect(() => {
    void fetch("/api/account/me").then(async (response) => {
      if (response.ok) setAccount(await response.json());
    });
  }, []);
  const total = items.reduce((n, x) => n + x.price.amount * x.quantity, 0);
  async function submit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    if (!items.length) return;
    setBusy(true);
    setError("");
    const data = new FormData(e.currentTarget);
    const customer = {
      name: String(data.get("name")),
      email: String(data.get("email")),
      country: "CZ",
      address: String(data.get("address")),
      postalCode: String(data.get("postalCode")),
      district: String(data.get("district")),
    };
    const key = (keyRef.current ||= crypto.randomUUID());
    try {
      const res = await fetch("/api/checkout", {
        method: "POST",
        headers: { "Content-Type": "application/json", "Idempotency-Key": key },
        body: JSON.stringify({
          locale,
          customer,
          items: items.map((x) => ({
            variantId: x.variantId,
            quantity: x.quantity,
          })),
        }),
      });
      const result = await res.json();
      if (!res.ok)
        throw new Error(result.message || "Order could not be placed");
      sessionStorage.setItem("nodra-last-order", JSON.stringify(result));
      writeCart([]);
      router.push(
        `/${locale}/order?reference=${encodeURIComponent(result.reference)}&token=${encodeURIComponent(result.lookupToken)}`,
      );
    } catch (err) {
      setError(
        err instanceof Error ? err.message : "Order could not be placed",
      );
    } finally {
      setBusy(false);
    }
  }
  return (
    <main className="checkout-page">
      <Link href={`/${locale}/basket`} className="back-link">
        ← {t.bag}
      </Link>
      <div className="page-title">
        <p className="eyebrow">NODRA / {t.checkoutLabel}</p>
        <h1>{t.checkout}</h1>
      </div>
      <div className="checkout-layout">
        <form onSubmit={submit} className="checkout-form">
          <div className="form-heading">
            <span>01</span>
            <h2>{t.contact}</h2>
          </div>
          <div className="form-grid">
            <label>
              {t.name}
              <input
                key={account?.name}
                name="name"
                defaultValue={account?.name}
                required
                autoComplete="name"
                maxLength={160}
              />
            </label>
            <label>
              {t.email}
              <input
                type="email"
                name="email"
                key={account?.email}
                defaultValue={account?.email}
                required
                autoComplete="email"
                maxLength={180}
              />
            </label>
            <label className="wide">
              {t.address}
              <input
                name="address"
                required
                autoComplete="street-address"
                maxLength={255}
              />
            </label>
            <label>
              {t.postal}
              <input
                name="postalCode"
                required
                autoComplete="postal-code"
                inputMode="numeric"
                pattern="[0-9]{3} ?[0-9]{2}"
                maxLength={6}
              />
            </label>
            <label>
              {t.district}
              <input name="district" required maxLength={120} />
            </label>
          </div>
          <p className="delivery-scope">
            {account ? (
              t.pointsRule
            ) : (
              <Link href={`/${locale}/account`}>
                {t.googleSignIn} · {t.points}
              </Link>
            )}
          </p>
          <p className="delivery-scope">
            {t.country}:{" "}
            {locale === "cs"
              ? "Česká republika"
              : locale === "de"
                ? "Tschechien"
                : "Czechia"}{" "}
            · {t.czechOnly}
          </p>
          <div className="demo-notice">✦ {t.demo}</div>
          {error && (
            <p className="form-error" role="alert">
              {error}
            </p>
          )}
          <button
            disabled={busy || !items.length}
            className="button button-dark"
          >
            {busy ? "…" : t.place} <span>↗</span>
          </button>
        </form>
        <aside className="checkout-summary">
          <p className="eyebrow">NODRA / {t.summaryLabel}</p>
          {items.map((x) => (
            <div key={x.variantId} className="summary-line">
              <div>
                <strong>{x.name}</strong>
                <small>
                  {x.label} × {x.quantity}
                </small>
              </div>
              <span>
                {money(
                  {
                    amount: x.price.amount * x.quantity,
                    currency: x.price.currency,
                  },
                  locale,
                )}
              </span>
            </div>
          ))}
          <div className="summary-total">
            <span>{t.subtotal}</span>
            <strong>
              {items.length
                ? money(
                    { amount: total, currency: items[0].price.currency },
                    locale,
                  )
                : "—"}
            </strong>
          </div>
          <p>
            {t.shipping} — {locale === "cs" ? "89 Kč" : "3,90 €"}
          </p>
        </aside>
      </div>
    </main>
  );
}
