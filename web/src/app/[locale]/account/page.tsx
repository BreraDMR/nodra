"use client";

import { useEffect, useState } from "react";
import { useParams } from "next/navigation";
import Link from "next/link";
import { copy, isLocale } from "@/lib/shop";

type Account = {
  name: string;
  email: string;
  points: number;
  history: { reference: string; points: number; createdAt: string }[];
};

export default function AccountPage() {
  const { locale: raw } = useParams<{ locale: string }>();
  const locale = isLocale(raw) ? raw : "cs";
  const t = copy[locale];
  const [account, setAccount] = useState<Account | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  async function refresh() {
    const response = await fetch("/api/account/me", { cache: "no-store" });
    setAccount(response.ok ? ((await response.json()) as Account) : null);
    setLoading(false);
  }

  useEffect(() => {
    void fetch("/api/account/me", { cache: "no-store" })
      .then(async (response) => {
        setAccount(response.ok ? ((await response.json()) as Account) : null);
        setLoading(false);
        if (new URLSearchParams(window.location.search).has("error")) {
          setError(t.googleError);
        }
      })
      .catch(() => setLoading(false));
  }, [t]);

  async function demoLogin() {
    setError("");
    const response = await fetch(`/api/account/demo-login?locale=${locale}`, {
      method: "POST",
    });
    if (!response.ok) {
      setError(t.demoError);
      return;
    }
    await refresh();
  }

  async function signOut() {
    await fetch("/api/account/logout", { method: "POST" });
    setAccount(null);
  }

  return (
    <main className="account-page">
      <Link href={`/${locale}/shop`} className="back-link">
        ← {t.shop}
      </Link>
      <div className="account-intro">
        <p className="eyebrow">NODRA / RIDER CLUB</p>
        <h1>{t.account}</h1>
        <p>{t.pointsRule}</p>
      </div>
      {loading ? (
        <p>…</p>
      ) : account ? (
        <div className="account-panel">
          <div>
            <p className="eyebrow">{t.account}</p>
            <h2>{account.name}</h2>
            <p>{account.email}</p>
            <button className="text-button" onClick={signOut}>
              {t.signOut} ↗
            </button>
          </div>
          <div className="points-panel">
            <p className="eyebrow">{t.points}</p>
            <strong>{account.points}</strong>
            {account.history.length ? (
              <ul>
                {account.history.map((item) => (
                  <li key={item.reference}>
                    <span>{item.reference}</span>
                    <b>+{item.points}</b>
                  </li>
                ))}
              </ul>
            ) : (
              <p>{t.noPoints}</p>
            )}
          </div>
        </div>
      ) : (
        <div className="account-panel">
          <div>
            <h2>{t.points}</h2>
            <p>{t.pointsRule}</p>
          </div>
          <div className="account-signin">
            <a
              className="button button-dark"
              href={`/api/account/google/start?locale=${locale}`}
            >
              {t.googleSignIn} ↗
            </a>
            <button className="button button-light" onClick={demoLogin}>
              {t.demoSignIn} ↗
            </button>
            {error && (
              <p className="form-error" role="alert">
                {error}
              </p>
            )}
          </div>
        </div>
      )}
    </main>
  );
}
