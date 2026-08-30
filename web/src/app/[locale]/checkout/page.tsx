"use client";
import { useEffect, useMemo, useRef, useState } from "react";
import { useParams, useRouter } from "next/navigation";
import Link from "next/link";
import { useCartItems, writeCart } from "@/lib/cart";
import { isDemoMode } from "@/lib/demo";
import { channels, expectedText, orderCopy, pickupNoteText } from "@/lib/order";
import {
  availabilityText,
  copy,
  isLocale,
  money,
  newIdempotencyKey,
  type AccountSummary,
  type CheckoutQuote,
  type ContactChannel,
  type DeliveryMethod,
  type Fulfilment,
  type Money,
  type OrderReceipt,
  type Problem,
} from "@/lib/shop";

type Form = {
  name: string;
  email: string;
  phone: string;
  // empty until the customer or their account picks one, WhatsApp is shown meanwhile
  contactChannel: ContactChannel | "";
  address: string;
  city: string;
  postalCode: string;
  district: string;
  deliveryNote: string;
  privacy: boolean;
  marketing: boolean;
};
type Field = keyof (typeof orderCopy)["en"]["fieldErrors"];
// a quote answer and the request body it belongs to
type QuoteResult = {
  body: string;
  quote: CheckoutQuote | null;
  problem: Problem | null;
};

const emptyForm: Form = {
  name: "",
  email: "",
  phone: "",
  contactChannel: "",
  address: "",
  city: "",
  postalCode: "",
  district: "",
  deliveryNote: "",
  privacy: false,
  marketing: false,
};
// shown until the first quote says what is offered
const fallbackMethods: DeliveryMethod[] = ["pickup_andel", "prague_personal"];

// "customer.phone" or "consents[privacy]" -> the form field it's about
function fieldOf(path: string | null): Field | null {
  const match = /^(customer|consents)[.[](\w+)\]?$/.exec(path ?? "");
  if (!match) return null;
  const field =
    match[1] === "consents"
      ? match[2] === "privacy"
        ? "privacy"
        : null
      : match[2];
  return field && field in orderCopy.en.fieldErrors ? (field as Field) : null;
}

function FieldError({ text }: { text?: string }) {
  return text ? <small className="field-error">{text}</small> : null;
}

export default function Checkout() {
  const { locale: raw } = useParams<{ locale: string }>();
  const locale = isLocale(raw) ? raw : "en";
  const t = copy[locale];
  const o = orderCopy[locale];
  const router = useRouter();
  const items = useCartItems();
  const [form, setForm] = useState<Form>(emptyForm);
  const [method, setMethod] = useState<DeliveryMethod>("prague_personal");
  const [fulfilment, setFulfilment] = useState<Fulfilment>("together");
  const [account, setAccount] = useState<AccountSummary | null>(null);
  const [result, setResult] = useState<QuoteResult | null>(null);
  const [retry, setRetry] = useState(0);
  const [fieldErrors, setFieldErrors] = useState<
    Partial<Record<Field, string>>
  >({});
  const [error, setError] = useState<{
    text: string;
    variantId?: string;
  } | null>(null);
  const [notice, setNotice] = useState("");
  const [busy, setBusy] = useState(false);
  // One Idempotency-Key per attempt: sending the same details again reuses it,
  // any change the customer makes gets a fresh one
  const attempt = useRef<{ key: string; body: string } | null>(null);
  const quotedPostal = useRef("");

  useEffect(() => {
    void fetch("/api/account/me", { cache: "no-store" })
      .then(async (response) => {
        if (!response.ok) return;
        const me = (await response.json()) as AccountSummary;
        const last = me.lastDelivery;
        setAccount(me);
        // only fill what the customer hasn't typed already
        setForm((f) => ({
          ...f,
          name: f.name || me.name,
          email: f.email || me.email,
          phone: f.phone || last?.phone || "",
          contactChannel: f.contactChannel || last?.contactChannel || "",
          address: f.address || last?.address || "",
          city: f.city || last?.city || "",
          postalCode: f.postalCode || last?.postalCode || "",
          district: f.district || last?.district || "",
        }));
      })
      .catch(() => {});
  }, []);

  const lines = useMemo(
    () => items.map((x) => ({ variantId: x.variantId, quantity: x.quantity })),
    [items],
  );
  const hasItems = lines.length > 0;
  const postal = form.postalCode.trim();
  const quoteBody = useMemo(
    () =>
      JSON.stringify({
        locale,
        items: lines,
        delivery: { method, postalCode: postal || null },
      }),
    [locale, lines, method, postal],
  );

  useEffect(() => {
    if (!hasItems) return;
    const controller = new AbortController();
    // let the customer finish typing the postal code, anything else asks right away
    const wait = postal === quotedPostal.current ? 0 : 500;
    const timer = window.setTimeout(() => {
      quotedPostal.current = postal;
      fetch("/api/checkout/quote", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: quoteBody,
        signal: controller.signal,
      })
        .then(async (response) => {
          const data: unknown = await response.json().catch(() => null);
          if (controller.signal.aborted) return;
          setResult(
            response.ok && data
              ? { body: quoteBody, quote: data as CheckoutQuote, problem: null }
              : {
                  body: quoteBody,
                  quote: null,
                  problem: (data as Problem | null) ?? { message: "" },
                },
          );
        })
        .catch(() => {
          if (controller.signal.aborted) return;
          setResult({ body: quoteBody, quote: null, problem: { message: "" } });
        });
    }, wait);
    return () => {
      window.clearTimeout(timer);
      controller.abort();
    };
  }, [quoteBody, postal, hasItems, retry]);

  // Only a quote for exactly what's on screen can be sent. The last answer stays
  // visible (dimmed) while the next one is on the way.
  const current = result?.body === quoteBody ? result : null;
  const loading = hasItems && !current;
  const quote = current?.quote ?? null;
  const shown = hasItems ? (result?.quote ?? null) : null;
  const problem = current?.problem ?? null;

  const offered = shown?.methods.map((m) => m.method) ?? fallbackMethods;
  const methods = offered.includes(method) ? offered : [...offered, method];
  const methodInfo = shown?.methods.find((m) => m.method === method) ?? null;
  const methodProblem =
    methodInfo && !methodInfo.available
      ? o.reasons[methodInfo.reason ?? "not_offered"]
      : problem?.code === "method_unavailable"
        ? o.reasons.not_offered
        : "";
  // a missing postal code is just the next step, not an error
  const methodWarning =
    !!methodProblem && methodInfo?.reason !== "postal_code_required";
  const methodNote =
    methodProblem ||
    (method === "pickup_andel"
      ? pickupNoteText(methodInfo?.note ?? null, locale)
      : method === "prague_personal"
        ? o.pragueNote
        : "");

  const options = shown?.options ?? null;
  // "split" only means something while the quote offers it
  const chosen: Fulfilment = options?.split ? fulfilment : "together";
  const shownOption =
    options && (chosen === "split" ? options.split : options.together);
  const option =
    quote?.options &&
    (chosen === "split" ? quote.options.split : quote.options.together);
  const blocked =
    shown?.lines.filter((l) => l.availability.status === "unavailable") ?? [];
  const canSubmit = !busy && !loading && !!quote?.canCheckout && !!option;

  const accountMismatch =
    !!account &&
    !!form.email.trim() &&
    form.email.trim().toLowerCase() !== account.email.toLowerCase();
  const accountEmail = account
    ? o.accountEmail.replace("{email}", account.email)
    : "";

  function nameOf(variantId?: string): string {
    return (
      shown?.lines.find((l) => l.variantId === variantId)?.name ??
      items.find((x) => x.variantId === variantId)?.name ??
      ""
    );
  }
  function fee(value: Money): string {
    return value.amount > 0 ? money(value, locale) : o.free;
  }
  function methodLabel(m: DeliveryMethod): string {
    const info = shown?.methods.find((x) => x.method === m);
    if (!info) return o.methods[m];
    // "Prague · 149 Kč · free from 500 Kč" while the basket is below the threshold
    const freeFrom =
      info.freeFromMinor !== null && (!info.fee || info.fee.amount > 0)
        ? o.freeFrom.replace(
            "{amount}",
            money({ amount: info.freeFromMinor, currency: "CZK" }, locale),
          )
        : "";
    // before a postal code is in, the threshold is still worth knowing
    const detail = info.available
      ? [info.fee ? fee(info.fee) : "", freeFrom].filter(Boolean).join(" · ")
      : info.reason === "postal_code_required"
        ? [o.reasonsShort.postal_code_required, freeFrom]
            .filter(Boolean)
            .join(" · ")
        : o.reasonsShort[info.reason ?? "not_offered"];
    return detail ? `${o.methods[m]} · ${detail}` : o.methods[m];
  }

  function set<K extends keyof Form>(field: K, value: Form[K]) {
    setForm((f) => ({ ...f, [field]: value }));
    setFieldErrors((errors) => {
      if (!(field in errors)) return errors;
      const next = { ...errors };
      delete next[field as string as Field];
      return next;
    });
  }
  function requote() {
    setResult(null);
    setRetry((n) => n + 1);
  }
  function remove(variantId: string) {
    writeCart(items.filter((x) => x.variantId !== variantId));
    setError(null);
  }

  // Turns an API error into something the customer can act on, in their language
  function explain(answer: Problem) {
    switch (answer.code) {
      case "quote_changed":
        if (!answer.quote) break;
        setResult({ body: quoteBody, quote: answer.quote, problem: null });
        setNotice(o.quoteChanged);
        return;
      case "split_unavailable":
        setFulfilment("together");
        setNotice(o.splitUnavailable);
        requote();
        return;
      case "method_unavailable":
        setError({ text: o.reasons[answer.reason ?? "not_offered"] });
        requote();
        return;
      case "unavailable":
      case "product_unavailable":
        setError({
          text: (answer.code === "unavailable"
            ? t.cannotOrder
            : o.productGone
          ).replace("{item}", nameOf(answer.variantId)),
          variantId: answer.variantId,
        });
        requote();
        return;
      case "idempotency_conflict":
        attempt.current = null;
        setError({ text: o.sendFailed });
        return;
    }
    const fields: Partial<Record<Field, string>> = {};
    for (const violation of answer.violations ?? []) {
      const field = fieldOf(violation.field);
      if (field)
        fields[field] =
          field === "email" && accountMismatch
            ? accountEmail
            : o.fieldErrors[field];
    }
    if (Object.keys(fields).length) {
      setFieldErrors(fields);
      setError({ text: o.checkFields });
      return;
    }
    setError({
      text: answer.message
        ? `${o.sendFailed} (${answer.message})`
        : o.sendFailed,
    });
  }

  async function submit(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    if (!canSubmit || !option) return;
    setBusy(true);
    setError(null);
    setNotice("");
    setFieldErrors({});
    const pickup = method === "pickup_andel";
    const contactChannel = form.contactChannel || "whatsapp";
    const payload = {
      locale,
      customer: {
        name: form.name.trim(),
        email: form.email.trim(),
        phone: form.phone.trim(),
        contactChannel,
        country: "CZ",
        // pickup needs no address, the API ignores it there anyway
        ...(pickup
          ? {}
          : {
              address: form.address.trim(),
              city: form.city.trim(),
              postalCode: postal,
              district: form.district.trim() || null,
            }),
        deliveryNote: form.deliveryNote.trim() || null,
      },
      items: lines,
      delivery: { method, fulfilment: option.fulfilment },
      consents: { privacy: form.privacy, marketing: form.marketing },
    };
    const body = JSON.stringify(payload);
    if (attempt.current?.body !== body)
      attempt.current = { key: newIdempotencyKey(), body };
    const key = attempt.current.key;
    try {
      const response = await fetch("/api/checkout", {
        method: "POST",
        headers: { "Content-Type": "application/json", "Idempotency-Key": key },
        body: JSON.stringify({
          ...payload,
          expectedTotal: option.total.amount,
        }),
      });
      const data: unknown = await response.json().catch(() => null);
      if (response.ok && data) {
        const receipt = data as OrderReceipt;
        try {
          // the receipt carries the channel now; this copy is only the order
          // page's fallback for a receipt that comes without one
          sessionStorage.setItem(
            "nodra-last-order",
            JSON.stringify({ reference: receipt.reference, contactChannel }),
          );
        } catch {
          // storage can be blocked, the order page then says "the channel you chose"
        }
        writeCart([]);
        router.push(
          `/${locale}/order?reference=${encodeURIComponent(receipt.reference)}&token=${encodeURIComponent(receipt.lookupToken)}`,
        );
        return;
      }
      explain((data as Problem | null) ?? { message: "" });
    } catch {
      setError({ text: o.sendFailed });
    } finally {
      setBusy(false);
    }
  }

  const quoteProblem = !problem
    ? ""
    : problem.code === "product_unavailable" || problem.code === "unavailable"
      ? o.productGone.replace("{item}", nameOf(problem.variantId))
      : problem.code === "method_unavailable"
        ? ""
        : o.quoteFailed;
  const quoteVariant = problem?.variantId;
  const errorVariant = error?.variantId;

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
            <h2>{o.contactHeading}</h2>
          </div>
          <div className="form-grid">
            <label>
              {t.name}
              <input
                name="name"
                value={form.name}
                onChange={(e) => set("name", e.target.value)}
                required
                autoComplete="name"
                maxLength={160}
                aria-invalid={!!fieldErrors.name}
              />
              <FieldError text={fieldErrors.name} />
            </label>
            <label>
              {t.email}
              <input
                type="email"
                name="email"
                value={form.email}
                onChange={(e) => set("email", e.target.value)}
                required
                autoComplete="email"
                maxLength={180}
                aria-invalid={!!fieldErrors.email}
              />
              {accountMismatch && !fieldErrors.email && (
                <small className="field-hint">{accountEmail}</small>
              )}
              <FieldError text={fieldErrors.email} />
            </label>
            <label>
              {o.phone}
              <input
                type="tel"
                name="phone"
                value={form.phone}
                onChange={(e) => set("phone", e.target.value)}
                required
                autoComplete="tel"
                placeholder="+420 …"
                maxLength={24}
                aria-invalid={!!fieldErrors.phone}
              />
              <FieldError text={fieldErrors.phone} />
            </label>
            <label>
              {o.channel}
              <select
                name="contactChannel"
                value={form.contactChannel || "whatsapp"}
                onChange={(e) =>
                  set("contactChannel", e.target.value as ContactChannel)
                }
              >
                {channels.map((c) => (
                  <option key={c} value={c}>
                    {o.channels[c]}
                  </option>
                ))}
              </select>
              <FieldError text={fieldErrors.contactChannel} />
            </label>
          </div>

          <div className="form-heading">
            <span>02</span>
            <h2>{o.deliveryHeading}</h2>
          </div>
          <div className="form-grid">
            <label className="wide">
              {o.method}
              <select
                name="method"
                value={method}
                onChange={(e) => {
                  setMethod(e.target.value as DeliveryMethod);
                  setError(null);
                }}
              >
                {methods.map((m) => (
                  <option key={m} value={m}>
                    {methodLabel(m)}
                  </option>
                ))}
              </select>
            </label>
            {methodNote && (
              <p
                className={`method-note wide${methodWarning ? " warning" : ""}`}
                aria-live="polite"
              >
                {methodNote}
              </p>
            )}
            {method !== "pickup_andel" && (
              <>
                <label className="wide">
                  {t.address}
                  <input
                    name="address"
                    value={form.address}
                    onChange={(e) => set("address", e.target.value)}
                    required
                    autoComplete="street-address"
                    maxLength={255}
                    aria-invalid={!!fieldErrors.address}
                  />
                  <FieldError text={fieldErrors.address} />
                </label>
                <label>
                  {o.city}
                  <input
                    name="city"
                    value={form.city}
                    onChange={(e) => set("city", e.target.value)}
                    required
                    autoComplete="address-level2"
                    maxLength={120}
                    aria-invalid={!!fieldErrors.city}
                  />
                  <FieldError text={fieldErrors.city} />
                </label>
                <label>
                  {t.postal}
                  <input
                    name="postalCode"
                    value={form.postalCode}
                    onChange={(e) => set("postalCode", e.target.value)}
                    required
                    autoComplete="postal-code"
                    inputMode="numeric"
                    pattern="[0-9]{3} ?[0-9]{2}"
                    placeholder="110 00"
                    maxLength={6}
                    aria-invalid={!!fieldErrors.postalCode}
                  />
                  <FieldError text={fieldErrors.postalCode} />
                </label>
                <label className="wide">
                  {o.district}
                  <input
                    name="district"
                    value={form.district}
                    onChange={(e) => set("district", e.target.value)}
                    maxLength={120}
                    aria-invalid={!!fieldErrors.district}
                  />
                  <FieldError text={fieldErrors.district} />
                </label>
              </>
            )}
            <label className="wide">
              {o.note}
              <textarea
                name="deliveryNote"
                value={form.deliveryNote}
                onChange={(e) => set("deliveryNote", e.target.value)}
                rows={3}
                maxLength={500}
                aria-invalid={!!fieldErrors.deliveryNote}
              />
              <FieldError text={fieldErrors.deliveryNote} />
            </label>
            {options?.split && (
              <div className="wide fulfilment">
                <label>
                  {o.fulfilment}
                  <select
                    name="fulfilment"
                    value={chosen}
                    onChange={(e) =>
                      setFulfilment(e.target.value as Fulfilment)
                    }
                  >
                    <option value="together">
                      {o.together} · {money(options.together.total, locale)}
                    </option>
                    <option value="split">
                      {o.split} · {money(options.split.total, locale)}
                    </option>
                  </select>
                </label>
                <p className="field-hint">{o.fulfilmentHelp}</p>
                <div className="delivery-options">
                  {[options.together, options.split].map((choice) => (
                    <div
                      key={choice.fulfilment}
                      className={`delivery-option${choice.fulfilment === chosen ? " selected" : ""}`}
                    >
                      <strong>
                        {choice.fulfilment === "together"
                          ? o.together
                          : o.split}
                      </strong>
                      <ul>
                        {choice.shipments.map((s) => (
                          <li key={s.number}>
                            <span>
                              {o.shipment.replace("{n}", String(s.number))}:{" "}
                              {s.variantIds.map((id) => nameOf(id)).join(", ")}
                            </span>
                            <small>
                              {expectedText(
                                locale,
                                s.leadTimeMinDays,
                                s.leadTimeMaxDays,
                              )}{" "}
                              · {fee(s.fee)}
                            </small>
                          </li>
                        ))}
                      </ul>
                      <p>
                        {t.shipping} {fee(choice.shipping)} · {t.total}{" "}
                        <b>{money(choice.total, locale)}</b>
                      </p>
                    </div>
                  ))}
                </div>
              </div>
            )}
          </div>

          <div className="form-heading">
            <span>03</span>
            <h2>{o.consentHeading}</h2>
          </div>
          <div className="consents">
            <label className="consent">
              <input
                type="checkbox"
                name="privacy"
                checked={form.privacy}
                onChange={(e) => set("privacy", e.target.checked)}
                required
                aria-invalid={!!fieldErrors.privacy}
              />
              <span>
                {o.privacyBefore}
                {/* plain text until the privacy page exists (D00.7), then a link */}
                {o.privacyLink}
                {o.privacyAfter}
              </span>
            </label>
            <FieldError text={fieldErrors.privacy} />
            <label className="consent">
              <input
                type="checkbox"
                name="marketing"
                checked={form.marketing}
                onChange={(e) => set("marketing", e.target.checked)}
              />
              <span>{o.marketing}</span>
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
            · {t.czechOnly}. {o.carrierOff}
          </p>
          <div className="demo-notice">✦ {t.demo}</div>
          {isDemoMode && (
            <p className="demo-order-note" role="note">
              {t.demoOrder}
            </p>
          )}
          {notice && (
            <p className="form-notice" role="status">
              {notice}
            </p>
          )}
          {error && (
            <p className="form-error" role="alert">
              {error.text}{" "}
              {errorVariant &&
                items.some((x) => x.variantId === errorVariant) && (
                  <button
                    type="button"
                    className="text-button"
                    onClick={() => remove(errorVariant)}
                  >
                    {t.remove}
                  </button>
                )}
            </p>
          )}
          <button disabled={!canSubmit} className="button button-dark">
            {busy ? "…" : t.place} <span>↗</span>
          </button>
        </form>
        <aside
          className={`checkout-summary${loading ? " is-loading" : ""}`}
          aria-busy={loading}
        >
          <p className="eyebrow">NODRA / {t.summaryLabel}</p>
          {!hasItems ? (
            <p>
              {t.empty} <Link href={`/${locale}/shop`}>{t.explore} ↗</Link>
            </p>
          ) : shown ? (
            shown.lines.map((line) => (
              <div key={line.variantId} className="summary-line">
                <div>
                  <strong>{line.name}</strong>
                  <small>
                    {line.variant} × {line.quantity}
                  </small>
                  <small
                    className={`product-availability ${line.availability.status}`}
                  >
                    {availabilityText(line.availability, locale)}
                  </small>
                  {line.availability.status === "unavailable" && (
                    <button
                      type="button"
                      className="text-button"
                      onClick={() => remove(line.variantId)}
                    >
                      {t.remove}
                    </button>
                  )}
                </div>
                <span>
                  {money(
                    { amount: line.lineTotal, currency: shown.currency },
                    locale,
                  )}
                </span>
              </div>
            ))
          ) : (
            items.map((x) => (
              <div key={x.variantId} className="summary-line">
                <div>
                  <strong>{x.name}</strong>
                  <small>
                    {x.label} × {x.quantity}
                  </small>
                </div>
                <span>…</span>
              </div>
            ))
          )}
          <div className="summary-row">
            <span>{t.subtotal}</span>
            <span>{shown ? money(shown.subtotal, locale) : "—"}</span>
          </div>
          <div className="summary-row">
            <span>{t.shipping}</span>
            <span>{shownOption ? fee(shownOption.shipping) : "—"}</span>
          </div>
          <div className="summary-total">
            <span>{t.total}</span>
            <strong>
              {shownOption ? money(shownOption.total, locale) : "—"}
            </strong>
          </div>
          {shownOption && (
            <ul className="summary-shipments">
              {shownOption.shipments.map((s) => (
                <li key={s.number}>
                  {shownOption.shipments.length > 1 &&
                    `${o.shipment.replace("{n}", String(s.number))} · `}
                  {expectedText(locale, s.leadTimeMinDays, s.leadTimeMaxDays)}
                </li>
              ))}
            </ul>
          )}
          {blocked.length > 0 && (
            <p className="form-error">{o.unavailableLines}</p>
          )}
          {quoteProblem && (
            <p className="form-error" role="alert">
              {quoteProblem}{" "}
              {quoteVariant ? (
                items.some((x) => x.variantId === quoteVariant) && (
                  <button
                    type="button"
                    className="text-button"
                    onClick={() => remove(quoteVariant)}
                  >
                    {t.remove}
                  </button>
                )
              ) : (
                <button type="button" className="text-button" onClick={requote}>
                  {o.retry}
                </button>
              )}
            </p>
          )}
          {o.czkNote && <p className="summary-note">{o.czkNote}</p>}
          <p aria-live="polite">{loading ? o.calculating : ""}</p>
        </aside>
      </div>
    </main>
  );
}
