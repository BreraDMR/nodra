"use client";
import Link from "next/link";
import { useEffect, useState } from "react";
import { copy, type Locale } from "@/lib/shop";
export function StoreHeader({ locale }: { locale: Locale }) {
  const t = copy[locale];
  const [count, setCount] = useState(0);
  useEffect(() => { const update = () => { try { setCount((JSON.parse(localStorage.getItem("nordra-cart") || "[]") as {quantity: number}[]).reduce((n, item) => n + item.quantity, 0)); } catch { setCount(0); } }; update(); window.addEventListener("cart-updated", update); return () => window.removeEventListener("cart-updated", update); }, []);
  return <><div className="announcement">NORDRA / DESIGNED FOR THE EVERYDAY ESCAPE <span>✦</span> PRAGUE · EVERYWHERE</div><header className="site-header"><div className="header-inner"><Link href={`/${locale}`} className="wordmark" aria-label="NORDRA home">NORDRA<span>®</span></Link><nav aria-label="Main navigation"><Link href={`/${locale}/shop`}>{t.shop}</Link><a href={`/${locale}#story`}>{t.story}</a></nav><div className="header-actions"><div className="locale-switch" aria-label="Language">{(["cs", "de", "en"] as const).map(l => <Link key={l} href={`/${l}`} className={locale === l ? "active" : ""}>{l.toUpperCase()}</Link>)}</div><Link href={`/${locale}/basket`} className="basket-link">{t.bag} <span>{count}</span></Link></div></div></header></>;
}
