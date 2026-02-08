"use client";

import Image from "next/image";
import Link from "next/link";
import { useCartItems, writeCart } from "@/lib/cart";
import { copy, isLocale, money, type CartItem } from "@/lib/shop";
import { useParams } from "next/navigation";
export default function Basket() {
  const { locale: raw } = useParams<{ locale: string }>();
  const locale = isLocale(raw) ? raw : "en";
  const t = copy[locale];
  const items = useCartItems();
  const update = (next: CartItem[]) => {
    writeCart(next);
  };
  const subtotal = items.reduce((n, x) => n + x.price.amount * x.quantity, 0);
  return (
    <main className="cart-page">
      <div className="page-title">
        <p className="eyebrow">NODRA / YOUR SELECTION</p>
        <h1>
          {t.bag}
          <sup>{items.reduce((n, x) => n + x.quantity, 0)}</sup>
        </h1>
      </div>
      {items.length ? (
        <div className="cart-layout">
          <div className="cart-lines">
            {items.map((item) => (
              <div className="cart-line" key={item.variantId}>
                <Link
                  href={`/${locale}/shop/${item.slug}`}
                  className="cart-thumb"
                >
                  <Image src={item.image} alt={item.name} fill sizes="120px" />
                </Link>
                <div className="cart-line-info">
                  <Link href={`/${locale}/shop/${item.slug}`}>
                    <h2>{item.name}</h2>
                  </Link>
                  <p>{item.label}</p>
                  <button
                    onClick={() =>
                      update(
                        items.filter((x) => x.variantId !== item.variantId),
                      )
                    }
                  >
                    {t.remove}
                  </button>
                </div>
                <div className="cart-line-end">
                  <strong>
                    {money(
                      {
                        amount: item.price.amount * item.quantity,
                        currency: item.price.currency,
                      },
                      locale,
                    )}
                  </strong>
                  <label>
                    {t.quantity}{" "}
                    <input
                      type="number"
                      min="1"
                      max="10"
                      value={item.quantity}
                      onChange={(e) =>
                        update(
                          items.map((x) =>
                            x.variantId === item.variantId
                              ? {
                                  ...x,
                                  quantity: Math.max(
                                    1,
                                    Math.min(10, Number(e.target.value) || 1),
                                  ),
                                }
                              : x,
                          ),
                        )
                      }
                    />
                  </label>
                </div>
              </div>
            ))}
          </div>
          <aside className="cart-summary">
            <h2>{t.total}</h2>
            <div>
              <span>{t.subtotal}</span>
              <strong>
                {money(
                  { amount: subtotal, currency: items[0].price.currency },
                  locale,
                )}
              </strong>
            </div>
            <p>
              {t.shipping} — {locale === "cs" ? "89 Kč" : "3,90 €"}
            </p>
            <Link href={`/${locale}/checkout`} className="button button-dark">
              {t.checkout} <span>↗</span>
            </Link>
            <small>{t.demo}</small>
          </aside>
        </div>
      ) : (
        <div className="empty-state">
          <h2>{t.empty}</h2>
          <Link href={`/${locale}/shop`} className="button button-dark">
            {t.explore} ↗
          </Link>
        </div>
      )}
    </main>
  );
}
