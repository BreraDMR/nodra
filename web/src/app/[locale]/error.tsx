"use client";

import { useParams } from "next/navigation";
import { useEffect } from "react";
import { isLocale, type Locale } from "@/lib/shop";

const text: Record<Locale, { title: string; body: string; retry: string }> = {
  cs: {
    title: "Něco se nepovedlo.",
    body: "Stránku se nepodařilo načíst. Zkuste to znovu, nebo se za chvíli vraťte.",
    retry: "Načíst znovu",
  },
  de: {
    title: "Etwas ist schiefgelaufen.",
    body: "Die Seite ließ sich nicht laden. Versuchen Sie es noch einmal, oder kommen Sie später zurück.",
    retry: "Neu laden",
  },
  en: {
    title: "Something went wrong.",
    body: "The page could not be loaded. Try again, or come back in a moment.",
    retry: "Load again",
  },
};

export default function Error({
  error,
  reset,
}: {
  error: Error & { digest?: string };
  reset: () => void;
}) {
  const { locale: raw } = useParams<{ locale: string }>();
  const locale = isLocale(raw) ? raw : "en";
  const t = text[locale];

  useEffect(() => {
    // keeps the cause findable in the browser console and the server logs
    console.error(error);
  }, [error]);

  return (
    <main className="page-error" role="alert">
      <p className="eyebrow">NODRA</p>
      <h1>{t.title}</h1>
      <p>{t.body}</p>
      <button className="button button-dark" onClick={reset}>
        {t.retry}
      </button>
    </main>
  );
}
