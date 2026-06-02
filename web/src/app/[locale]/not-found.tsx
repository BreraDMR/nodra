"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { isLocale, type Locale } from "@/lib/shop";

const text: Record<Locale, { title: string; body: string; link: string }> = {
  cs: {
    title: "Stránka nenalezena",
    body: "Tahle stránka neexistuje nebo se přesunula. Zkuste vyhledávání nahoře, nebo projděte celý katalog.",
    link: "Do katalogu",
  },
  de: {
    title: "Seite nicht gefunden",
    body: "Diese Seite gibt es nicht oder sie ist umgezogen. Nutzen Sie die Suche oben oder stöbern Sie im ganzen Katalog.",
    link: "Zum Katalog",
  },
  en: {
    title: "Page not found",
    body: "This page doesn't exist or has moved. Try the search above or browse the whole catalogue.",
    link: "Browse the catalogue",
  },
};

// not-found gets no params, so the language comes from the path itself
export default function NotFound() {
  const first = usePathname()?.split("/")[1] ?? "";
  const locale: Locale = isLocale(first) ? first : "cs";
  const t = text[locale];
  return (
    <main className="confirmation-page">
      <p className="eyebrow">NODRA / 404</p>
      <h1>{t.title}</h1>
      <p>{t.body}</p>
      <Link className="button button-dark" href={`/${locale}/shop`}>
        {t.link} ↗
      </Link>
    </main>
  );
}
