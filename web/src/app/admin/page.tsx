"use client";
import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import Image from "next/image";
import { AttributeFields } from "./AttributeFields";
import { CategoriesPanel } from "./CategoriesPanel";
import { Chip } from "./OrderPanel";
import {
  formatPrice,
  orderQueues,
  queueHints,
  queueLabels,
  urgentQueues,
  type OrderQueue,
  type OrderRow,
  type QueueCounts,
} from "./orders";
import { OrdersPanel } from "./OrdersPanel";
import ImportPanel from "./ImportPanel";
import { PricingRulesPanel } from "./PricingRulesPanel";
import { PurchasesPanel } from "./PurchasesPanel";
import { RepricePanel, type RepriceFilter } from "./RepricePanel";
import { SupplierOffersPanel } from "./SupplierOffersPanel";
import { ProductPricingPanel } from "./VariantPricingPanel";
import {
  attributePayload,
  attributeValues,
  errorText,
  formatCzk,
  formatMinor,
  indent,
  isVisible,
  json,
  minorText,
  moneyHint,
  moneyPattern,
  parseMinor,
  percent,
  pricingThresholds,
  type AdminCategory,
  type AttributeValues,
  type PriceApplied,
  type PricingAlerts,
  type PricingThresholds,
  type Save,
  type Send,
  type SupplierOffer,
} from "./shared";
type User = { email: string; name: string; csrfToken: string };
type Variant = {
  id: string;
  sku: string;
  label: Record<string, string>;
  priceCzk: number;
  priceEur: number;
  stock: number;
  active: boolean;
  color: string | null;
  size: string | null;
  mpn: string | null;
  ean: string | null;
  attributes: unknown;
  rrpMinor: number | null;
  rrpCurrency: string | null;
  rrpSource: string | null;
  rrpCheckedAt: string | null;
  marketPriceMinor: number | null;
  marketPriceSource: string | null;
  marketCheckedAt: string | null;
};
type Product = {
  id: string;
  slug: string;
  category: string;
  categoryId: string;
  brand: string | null;
  attributes: unknown;
  status: string;
  name: string;
  copy: Record<string, { name: string; short: string; inBox?: string }>;
  image: string;
  badge: string | null;
  featuredRank: number;
  supplierOffers: SupplierOffer[];
  variants: Variant[];
};
type Dashboard = {
  orders: number;
  // requested + confirmed
  openOrders: number;
  // CZK orders that aren't cancelled; EUR is only the older demo orders
  revenueCzkMinor: number;
  revenueEurMinor: number;
  products: number;
  // variants NODRA holds itself, lowest first, up to 20 of ownStockVariants
  ownStock: {
    id: string;
    sku: string;
    stock: number;
    product: string | null;
  }[];
  ownStockVariants: number;
  queues: QueueCounts;
  recentOrders: OrderRow[];
};
type Tab =
  | "overview"
  | "products"
  | "categories"
  | "pricing"
  | "reprice"
  | "orders"
  | "purchases"
  | "import";
const fresh = {
  slug: "",
  category: "bags",
  nameCs: "",
  nameDe: "",
  nameEn: "",
  shortCs: "",
  shortDe: "",
  shortEn: "",
  image: "/images/pannier.png",
  status: "draft",
  // typed in Kč and €, turned into haléře/cents on save
  priceCzk: "0",
  priceEur: "0",
  badge: "",
  featuredRank: 100,
  brand: "",
  inBoxCs: "",
  inBoxDe: "",
  inBoxEn: "",
  attributes: {} as AttributeValues,
};
type Form = typeof fresh;
const optionalFields: (keyof Form)[] = [
  "badge",
  "brand",
  "inBoxCs",
  "inBoxDe",
  "inBoxEn",
];
const variantFresh = {
  sku: "",
  labelCs: "",
  labelDe: "",
  labelEn: "",
  priceCzk: "",
  priceEur: "",
  active: true,
  color: "",
  size: "",
  mpn: "",
  ean: "",
  attributes: {} as AttributeValues,
  rrp: "",
  rrpCurrency: "CZK",
  rrpSource: "",
  rrpCheckedAt: "",
  marketPrice: "",
  marketPriceSource: "",
  marketCheckedAt: "",
};
type VariantForm = typeof variantFresh;
// Price inputs to minor units; an empty required price is an error, not zero
function requiredMinor(text: string, label: string): number {
  const value = parseMinor(text);
  if (value === null) throw Error(`${label} is required`);
  return value;
}
// The variant whose price the product form edits; same order the API uses on PUT
function baseVariant(p: Product): Variant | undefined {
  return [...p.variants].sort(
    (a, b) =>
      Number(b.active && b.stock > 0) - Number(a.active && a.stock > 0) ||
      Number(b.active) - Number(a.active) ||
      a.sku.localeCompare(b.sku),
  )[0];
}
function fromProduct(p: Product): Form {
  const base = baseVariant(p);
  return {
    slug: p.slug,
    category: p.category,
    nameCs: p.copy.cs?.name || "",
    nameDe: p.copy.de?.name || "",
    nameEn: p.copy.en?.name || "",
    shortCs: p.copy.cs?.short || "",
    shortDe: p.copy.de?.short || "",
    shortEn: p.copy.en?.short || "",
    image: p.image,
    status: p.status,
    priceCzk: minorText(base?.priceCzk ?? 0),
    priceEur: minorText(base?.priceEur ?? 0),
    badge: p.badge || "",
    featuredRank: p.featuredRank,
    brand: p.brand || "",
    inBoxCs: p.copy.cs?.inBox || "",
    inBoxDe: p.copy.de?.inBox || "",
    inBoxEn: p.copy.en?.inBox || "",
    attributes: attributeValues(p.attributes),
  };
}
function productPrice(p: Product): number | null {
  const active = p.variants.filter((variant) => variant.active);
  const available = active.filter((variant) => variant.stock > 0);
  const prices = (available.length ? available : active).map(
    (variant) => variant.priceEur,
  );
  return prices.length ? Math.min(...prices) : null;
}
export default function AdminPage() {
  const [user, setUser] = useState<User | null>(null);
  const [ready, setReady] = useState(false);
  const [tab, setTab] = useState<Tab>("overview");
  const [dashboard, setDashboard] = useState<Dashboard | null>(null);
  const [products, setProducts] = useState<Product[]>([]);
  const [productPage, setProductPage] = useState(1);
  const [productSearch, setProductSearch] = useState("");
  const [productSearchDraft, setProductSearchDraft] = useState("");
  const [productPagination, setProductPagination] = useState({
    page: 1,
    pages: 1,
    total: 0,
  });
  // a recent order clicked on the overview opens as soon as the orders screen mounts
  const [orderToOpen, setOrderToOpen] = useState<string | null>(null);
  // a queue tile opens the orders list filtered by it; the key remounts the list
  const [ordersQueue, setOrdersQueue] = useState<OrderQueue | "">("");
  const [ordersKey, setOrdersKey] = useState(0);
  const [editing, setEditing] = useState<string | null>(null);
  const [variantEditing, setVariantEditing] = useState<{
    productId: string;
    id?: string;
  } | null>(null);
  const [variantForm, setVariantForm] = useState<VariantForm>(variantFresh);
  const [form, setForm] = useState<Form>(fresh);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const [categories, setCategories] = useState<AdminCategory[]>([]);
  const [alerts, setAlerts] = useState<PricingAlerts | null>(null);
  const [thresholds, setThresholds] = useState<PricingThresholds | null>(null);
  const [repriceFilter, setRepriceFilter] = useState<RepriceFilter>("all");
  // remounts the reprice screen when an alert opens it with another filter
  const [repriceKey, setRepriceKey] = useState(0);
  // bumped after an offer save so the open pricing panel reloads its cost
  const [pricingRevision, setPricingRevision] = useState(0);
  const loadCategories = useCallback(async () => {
    try {
      setCategories(await json("/api/admin/categories"));
    } catch (e) {
      setError(e instanceof Error ? e.message : "Could not load categories");
    }
  }, []);
  // the orders screen loads its own list; order actions call this for the rest
  const reload = useCallback(async (page: number, search: string) => {
    try {
      const query = new URLSearchParams({ page: String(page) });
      if (search) query.set("q", search);
      const [d, p, a] = await Promise.all([
        json("/api/admin/dashboard"),
        json(`/api/admin/products?${query}`),
        json("/api/admin/pricing/alerts"),
      ]);
      setDashboard(d);
      setAlerts(a);
      setProducts(p.items);
      setProductPagination({ page: p.page, pages: p.pages, total: p.total });
    } catch (e) {
      setError(e instanceof Error ? e.message : "Could not load admin data");
    }
  }, []);
  useEffect(() => {
    json("/api/admin/me")
      .then(async (u: User) => {
        setUser(u);
        await Promise.all([reload(1, ""), loadCategories()]);
      })
      .catch(() => {})
      .finally(() => setReady(true));
  }, [reload, loadCategories]);
  // the thresholds are static app config; if they don't load the tiles just keep the words
  useEffect(() => {
    pricingThresholds()
      .then(setThresholds)
      .catch(() => {});
  }, []);
  async function login(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setBusy(true);
    setError("");
    const d = new FormData(e.currentTarget);
    try {
      const u = await json("/api/admin/login", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          email: d.get("email"),
          password: d.get("password"),
        }),
      });
      setUser(u);
      await Promise.all([reload(1, ""), loadCategories()]);
    } catch (e) {
      setError(e instanceof Error ? e.message : "Sign-in failed");
    } finally {
      setBusy(false);
    }
  }
  // For panels that handle their own errors (409 stale or overlapping). Writes carry
  // the same CSRF token as mutate(); a DELETE goes out without a body.
  const send = useCallback<Send>(
    (url, method = "GET", body, headers) =>
      json(url, {
        method,
        headers:
          method === "GET"
            ? headers
            : {
                ...(body === undefined
                  ? {}
                  : { "Content-Type": "application/json" }),
                "X-CSRF-Token": user?.csrfToken || "",
                ...headers,
              },
        body: body === undefined ? undefined : JSON.stringify(body),
      }),
    [user],
  );
  // prices, stock or orders moved: dashboard, products and alerts follow
  function pricesChanged() {
    void reload(productPage, productSearch);
  }
  function openOrders(orderId: string | null, queue: OrderQueue | "" = "") {
    setOrderToOpen(orderId);
    setOrdersQueue(queue);
    setOrdersKey((key) => key + 1);
    setTab("orders");
  }
  // Product PUT writes the form price into the base variant, so after an apply in
  // the product editor the form must follow, or "Save product" would undo it
  function suggestionApplied(applied: PriceApplied) {
    const product = products.find((p) => p.id === editing);
    if (product && baseVariant(product)?.id === applied.variantId)
      setForm((current) => ({
        ...current,
        priceCzk: minorText(applied.newPriceCzk),
        priceEur: minorText(applied.newPriceEur),
      }));
    pricesChanged();
  }
  function openReprice(filter: RepriceFilter) {
    setRepriceFilter(filter);
    setRepriceKey((key) => key + 1);
    setTab("reprice");
  }
  const saveOffer: Save = async (url, method, body) => {
    const ok = await mutate(url, method, body);
    if (ok) setPricingRevision((n) => n + 1);
    return ok;
  };
  async function mutate(url: string, method: string, body: unknown) {
    if (!user) return;
    setBusy(true);
    setError("");
    setMessage("");
    try {
      await json(url, {
        method,
        headers: {
          "Content-Type": "application/json",
          "X-CSRF-Token": user.csrfToken,
        },
        body: JSON.stringify(body),
      });
      setMessage("Saved successfully.");
      await reload(productPage, productSearch);
      return true;
    } catch (e) {
      setError(e instanceof Error ? e.message : "Save failed");
      return false;
    } finally {
      setBusy(false);
    }
  }
  async function saveProduct(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    let priceCzk: number, priceEur: number;
    try {
      priceCzk = requiredMinor(form.priceCzk, "Price CZK");
      priceEur = requiredMinor(form.priceEur, "Price EUR");
    } catch (err) {
      setError(errorText(err, "Check the prices"));
      return;
    }
    const ok = await mutate(
      editing !== "new"
        ? `/api/admin/products/${editing}`
        : "/api/admin/products",
      editing !== "new" ? "PUT" : "POST",
      {
        ...form,
        badge: form.badge || null,
        brand: form.brand.trim() || null,
        inBoxCs: form.inBoxCs.trim() || null,
        inBoxDe: form.inBoxDe.trim() || null,
        inBoxEn: form.inBoxEn.trim() || null,
        attributes: attributePayload(
          form.attributes,
          categoryBySlug(form.category)?.effectiveAttributes,
        ),
        priceCzk,
        priceEur,
        featuredRank: Number(form.featuredRank),
      },
    );
    if (ok) {
      setEditing(null);
      // product counts per category may have moved
      void loadCategories();
    }
  }
  async function saveCategory(url: string, method: string, body: unknown) {
    const ok = await mutate(url, method, body);
    if (ok) await loadCategories();
    return ok;
  }
  function categoryBySlug(slug: string) {
    return categories.find((c) => c.slug === slug);
  }
  async function saveVariant(e: React.FormEvent<HTMLFormElement>) {
    e.preventDefault();
    if (!variantEditing) return;
    const f = variantForm;
    let money;
    try {
      const rrpMinor = parseMinor(f.rrp);
      const marketPriceMinor = parseMinor(f.marketPrice);
      // the API drops source and date without an amount, so don't lose them quietly
      if (rrpMinor === null && (f.rrpSource.trim() || f.rrpCheckedAt))
        throw Error("Enter the RRP amount, or clear its source and date");
      if (
        marketPriceMinor === null &&
        (f.marketPriceSource.trim() || f.marketCheckedAt)
      )
        throw Error("Enter the market price, or clear its source and date");
      if (rrpMinor === 0 || marketPriceMinor === 0)
        throw Error("RRP and market price must be above zero, or left empty");
      money = {
        priceCzk: requiredMinor(f.priceCzk, "Price CZK"),
        priceEur: requiredMinor(f.priceEur, "Price EUR"),
        rrpMinor,
        rrpCurrency: rrpMinor === null ? null : f.rrpCurrency,
        rrpSource: rrpMinor === null ? null : f.rrpSource.trim() || null,
        rrpCheckedAt: rrpMinor === null ? null : f.rrpCheckedAt || null,
        marketPriceMinor,
        marketPriceSource:
          marketPriceMinor === null ? null : f.marketPriceSource.trim() || null,
        marketCheckedAt:
          marketPriceMinor === null ? null : f.marketCheckedAt || null,
      };
    } catch (err) {
      setError(errorText(err, "Check the prices"));
      return;
    }
    // PUT replaces the whole variant, so every field goes out, changed or not
    const ok = await mutate(
      variantEditing.id
        ? `/api/admin/variants/${variantEditing.id}`
        : `/api/admin/products/${variantEditing.productId}/variants`,
      variantEditing.id ? "PUT" : "POST",
      {
        sku: f.sku,
        labelCs: f.labelCs,
        labelDe: f.labelDe,
        labelEn: f.labelEn,
        active: f.active,
        color: f.color || null,
        size: f.size || null,
        mpn: f.mpn.trim() || null,
        ean: f.ean.replace(/[\s-]/g, "") || null,
        attributes: attributePayload(
          f.attributes,
          categoryBySlug(
            products.find((p) => p.id === variantEditing.productId)?.category ||
              "",
          )?.effectiveAttributes,
        ),
        ...money,
      },
    );
    if (ok) setVariantEditing(null);
  }
  function editVariant(productId: string, variant?: Variant) {
    setError("");
    setVariantEditing({ productId, id: variant?.id });
    setVariantForm(
      variant
        ? {
            sku: variant.sku,
            labelCs: variant.label.cs,
            labelDe: variant.label.de,
            labelEn: variant.label.en,
            priceCzk: minorText(variant.priceCzk),
            priceEur: minorText(variant.priceEur),
            active: variant.active,
            color: variant.color || "",
            size: variant.size || "",
            mpn: variant.mpn || "",
            ean: variant.ean || "",
            attributes: attributeValues(variant.attributes),
            rrp: minorText(variant.rrpMinor),
            rrpCurrency: variant.rrpCurrency || "CZK",
            rrpSource: variant.rrpSource || "",
            rrpCheckedAt: (variant.rrpCheckedAt || "").slice(0, 10),
            marketPrice: minorText(variant.marketPriceMinor),
            marketPriceSource: variant.marketPriceSource || "",
            marketCheckedAt: (variant.marketCheckedAt || "").slice(0, 10),
          }
        : variantFresh,
    );
  }
  async function stock(variant: Variant) {
    const input = prompt(
      `Adjust ${variant.sku} (current: ${variant.stock}). Enter signed quantity:`,
      "1",
    );
    if (input === null) return;
    const delta = Number(input);
    if (!Number.isInteger(delta) || delta === 0) {
      setError("Enter a non-zero whole number.");
      return;
    }
    const reason = prompt(
      "Reason for stock adjustment:",
      "Cycle count correction",
    );
    if (!reason) return;
    await mutate("/api/admin/stock-adjustments", "POST", {
      variantId: variant.id,
      delta,
      reason,
    });
  }
  const field = (
    key: keyof Form,
    label: string,
    kind: "text" | "number" | "money" = "text",
  ) => (
    <label className="admin-field">
      {label}
      <input
        type={kind === "number" ? "number" : "text"}
        inputMode={kind === "money" ? "decimal" : undefined}
        pattern={kind === "money" ? moneyPattern : undefined}
        title={kind === "money" ? moneyHint : undefined}
        value={String(form[key])}
        onChange={(e) =>
          setForm({
            ...form,
            [key]: kind === "number" ? Number(e.target.value) : e.target.value,
          })
        }
        required={!optionalFields.includes(key)}
      />
    </label>
  );
  if (!ready)
    return <main className="admin-loading">Loading NODRA Studio…</main>;
  if (!user)
    return (
      <main className="admin-login">
        <div className="admin-login-art">
          <Image
            src="/images/hero-prague.png"
            alt="Cyclist in Prague"
            fill
            sizes="50vw"
          />
          <div>
            <span>NODRA / STUDIO</span>
            <h1>
              Keep the
              <br />
              journey moving.
            </h1>
          </div>
        </div>
        <div className="admin-login-panel">
          <Link href="/cs" className="admin-wordmark">
            NODRA
          </Link>
          <div className="admin-login-box">
            <p className="eyebrow">OPERATIONS / SIGN IN</p>
            <h2>Welcome back.</h2>
            <p>Manage the collection, stock and orders in one place.</p>
            <form onSubmit={login}>
              <label>
                Email
                <input
                  type="email"
                  name="email"
                  required
                  defaultValue="admin@nodra.test"
                />
              </label>
              <label>
                Password
                <input type="password" name="password" required />
              </label>
              {error && (
                <p className="admin-error" role="alert">
                  {error}
                </p>
              )}
              <button disabled={busy}>
                Sign in <span>↗</span>
              </button>
            </form>
            <small>Local demo account: admin@nodra.test / NodraDemo2026!</small>
          </div>
          <span className="admin-login-foot">NODRA STUDIO © 2026</span>
        </div>
      </main>
    );
  const editingProduct = products.find((product) => product.id === editing);
  const variantProduct = products.find(
    (product) => product.id === variantEditing?.productId,
  );
  const categoryById = new Map(categories.map((c) => [c.id, c]));
  return (
    <main className="admin-shell">
      <aside className="admin-sidebar">
        <div>
          <Link href="/cs" className="admin-wordmark">
            NODRA
          </Link>
          <p>STUDIO / COMMERCE</p>
          <nav>
            <button
              aria-label="Overview"
              className={tab === "overview" ? "active" : ""}
              onClick={() => setTab("overview")}
            >
              ◫ <span>Overview</span>
            </button>
            <button
              aria-label="Products"
              className={tab === "products" ? "active" : ""}
              onClick={() => setTab("products")}
            >
              ▦ <span>Products</span>
              <small>{productPagination.total}</small>
            </button>
            <button
              aria-label="Categories"
              className={tab === "categories" ? "active" : ""}
              onClick={() => setTab("categories")}
            >
              ▧ <span>Categories</span>
              <small>{categories.length}</small>
            </button>
            <button
              aria-label="Pricing rules"
              className={tab === "pricing" ? "active" : ""}
              onClick={() => setTab("pricing")}
            >
              ◩ <span>Pricing rules</span>
            </button>
            <button
              aria-label="Reprice"
              className={tab === "reprice" ? "active" : ""}
              onClick={() => openReprice("all")}
            >
              ◪ <span>Reprice</span>
            </button>
            <button
              aria-label="Orders"
              className={tab === "orders" ? "active" : ""}
              onClick={() => openOrders(null)}
            >
              ▤ <span>Orders</span>
              <small>{dashboard?.orders ?? 0}</small>
            </button>
            <button
              aria-label="Purchases"
              className={tab === "purchases" ? "active" : ""}
              onClick={() => setTab("purchases")}
            >
              ▥ <span>Purchases</span>
              <small>{dashboard?.queues.to_purchase ?? 0}</small>
            </button>
            <button
              aria-label="Import"
              className={tab === "import" ? "active" : ""}
              onClick={() => setTab("import")}
            >
              ▦ <span>Import</span>
            </button>
          </nav>
        </div>
        <div className="admin-sidebar-bottom">
          <Link href="/cs" target="_blank">
            ↗ &nbsp; View storefront
          </Link>
          <button
            onClick={async () => {
              // Symfony answers the logout with a redirect to the API host; don't follow it,
              // or CORS throws and the page never reloads to the login screen
              await fetch("/api/admin/logout", {
                method: "POST",
                redirect: "manual",
              }).catch(() => {});
              location.reload();
            }}
          >
            ↩ &nbsp; Sign out
          </button>
          <small>DEMO ENVIRONMENT</small>
        </div>
      </aside>
      <div className="admin-content">
        <header className="admin-topbar">
          <span>NODRA / OPERATIONS</span>
          <div>
            <span className="admin-status">● &nbsp; Local demo</span>
            <span className="admin-avatar">NS</span>
          </div>
        </header>
        <div className="admin-body">
          {message && (
            <div className="admin-success" role="status">
              ✓ {message}
              <button onClick={() => setMessage("")}>×</button>
            </div>
          )}
          {error && (
            <div className="admin-error" role="alert">
              {error}
              <button onClick={() => setError("")}>×</button>
            </div>
          )}
          {tab === "overview" && (
            <>
              <div className="admin-heading">
                <div>
                  <p className="eyebrow">
                    {new Intl.DateTimeFormat("en-GB", {
                      weekday: "long",
                      month: "long",
                      day: "numeric",
                      year: "numeric",
                    })
                      .format(new Date())
                      .toUpperCase()}
                  </p>
                  <h1>
                    Good morning,
                    <br />
                    <em>NODRA Studio.</em>
                  </h1>
                  <p>Here is what is happening in your shop.</p>
                </div>
                <div className="admin-heading-icon">↗</div>
              </div>
              <div className="metric-grid">
                <div>
                  <span>01 / REVENUE</span>
                  <strong>{formatCzk(dashboard?.revenueCzkMinor || 0)}</strong>
                  <small>
                    Orders not cancelled
                    {dashboard?.revenueEurMinor
                      ? ` · plus ${formatMinor(dashboard.revenueEurMinor, "EUR")} older EUR demo orders`
                      : ""}
                  </small>
                </div>
                <div>
                  <span>02 / ORDERS</span>
                  <strong>{dashboard?.orders || 0}</strong>
                  <small>
                    {dashboard?.openOrders || 0} open · requested or confirmed
                  </small>
                </div>
                <div>
                  <span>03 / PRODUCTS</span>
                  <strong>{dashboard?.products || 0}</strong>
                  <small>In the collection</small>
                </div>
                <div>
                  <span>04 / OWN STOCK</span>
                  <strong>{dashboard?.ownStockVariants || 0}</strong>
                  <small>Variants NODRA holds itself</small>
                </div>
              </div>
              <section className="admin-panel pricing-watch">
                <div className="panel-head">
                  <div>
                    <p className="eyebrow">WORK QUEUES</p>
                    <h2>What needs doing</h2>
                  </div>
                  <button onClick={() => openOrders(null)}>All orders ↗</button>
                </div>
                <div className="queue-grid">
                  {orderQueues.map((queue) => {
                    const count = dashboard?.queues[queue] ?? 0;
                    return (
                      <button
                        key={queue}
                        className={
                          count && urgentQueues.has(queue)
                            ? "alert"
                            : count
                              ? "open"
                              : ""
                        }
                        title={`Open orders in “${queueLabels[queue]}”`}
                        onClick={() => openOrders(null, queue)}
                      >
                        <strong>{dashboard ? count : "—"}</strong>
                        <span>{queueLabels[queue]}</span>
                        <small>{queueHints[queue]}</small>
                      </button>
                    );
                  })}
                </div>
                <p className="variant-note">
                  An order can sit in several queues at once.
                </p>
              </section>
              <section className="admin-panel pricing-watch">
                <div className="panel-head">
                  <div>
                    <p className="eyebrow">PRICING WATCH</p>
                    <h2>Needs attention</h2>
                  </div>
                  <button onClick={() => setTab("pricing")}>
                    Pricing rules ↗
                  </button>
                </div>
                <div className="pricing-watch-grid">
                  <button
                    className={alerts?.marginTooLow ? "alert" : ""}
                    onClick={() => openReprice("margin_too_low")}
                  >
                    <strong>{alerts?.marginTooLow ?? "—"}</strong>
                    <span>Margin too low</span>
                    <small>
                      Suggestion held up by the{" "}
                      {thresholds ? `${percent(thresholds.minMarginBp)} ` : ""}
                      minimum margin. Review in Reprice ↗
                    </small>
                  </button>
                  <button
                    className={alerts?.aboveMarket ? "alert" : ""}
                    onClick={() => openReprice("above_market")}
                  >
                    <strong>{alerts?.aboveMarket ?? "—"}</strong>
                    <span>Above market</span>
                    <small>
                      Current price over the{" "}
                      {thresholds
                        ? `${percent(thresholds.aboveMarketBp)} `
                        : ""}
                      market threshold. Review in Reprice ↗
                    </small>
                  </button>
                  <button
                    className={alerts?.checkNeeded ? "alert" : ""}
                    onClick={() => setTab("products")}
                  >
                    <strong>{alerts?.checkNeeded ?? "—"}</strong>
                    <span>Check needed</span>
                    <small>
                      No fresh matched offer with a lead time. Match offers in
                      Products ↗
                    </small>
                  </button>
                </div>
                <p className="variant-note">
                  Counts cover active variants of published products.
                </p>
              </section>
              <div className="admin-overview-grid">
                <section className="admin-panel">
                  <div className="panel-head">
                    <div>
                      <p className="eyebrow">LIVE OPERATIONS</p>
                      <h2>Recent orders</h2>
                    </div>
                    <button onClick={() => openOrders(null)}>View all ↗</button>
                  </div>
                  {dashboard?.recentOrders.length ? (
                    dashboard.recentOrders.map((o) => (
                      <button
                        type="button"
                        className="mini-order"
                        key={o.id}
                        title={`Open ${o.reference}`}
                        onClick={() => openOrders(o.id)}
                      >
                        <span className="order-ref">
                          {o.reference}
                          {o.delayed && (
                            <span className="delayed-badge">Delayed</span>
                          )}
                        </span>
                        <span>{o.customerName}</span>
                        <span className="mini-order-status">
                          <Chip value={o.status} />
                          <Chip value={o.paymentStatus} />
                        </span>
                        <strong>{formatPrice(o.total)}</strong>
                      </button>
                    ))
                  ) : (
                    <p className="admin-empty">
                      No orders yet. Place a demo order through the storefront.
                    </p>
                  )}
                </section>
                <section className="admin-panel">
                  <div className="panel-head">
                    <div>
                      <p className="eyebrow">INVENTORY WATCH</p>
                      <h2>Own stock</h2>
                    </div>
                    <button onClick={() => setTab("products")}>Manage ↗</button>
                  </div>
                  {dashboard?.ownStock.length ? (
                    <>
                      {dashboard.ownStock.map((x) => (
                        <div className="low-stock" key={x.id}>
                          <div>
                            <strong>{x.product || x.sku}</strong>
                            <small>{x.sku}</small>
                          </div>
                          <b>{x.stock} on hand</b>
                        </div>
                      ))}
                      {dashboard.ownStockVariants >
                        dashboard.ownStock.length && (
                        <p className="variant-note">
                          Lowest {dashboard.ownStock.length} of{" "}
                          {dashboard.ownStockVariants} variants with own stock.
                        </p>
                      )}
                    </>
                  ) : (
                    <p className="admin-empty">
                      NODRA holds no stock of its own right now. Everything is
                      bought after the customer confirms.
                    </p>
                  )}
                </section>
              </div>
            </>
          )}
          {tab === "products" && (
            <>
              <div className="admin-heading compact">
                <div>
                  <p className="eyebrow">CATALOG / INVENTORY</p>
                  <h1>
                    Products<span>.</span>
                  </h1>
                  <p>Keep every item ready for the next ride.</p>
                </div>
                <button
                  className="admin-primary"
                  onClick={() => {
                    setForm(fresh);
                    setEditing("new");
                  }}
                >
                  + Add product
                </button>
              </div>
              <form
                className="admin-product-search"
                onSubmit={(event) => {
                  event.preventDefault();
                  setProductPage(1);
                  const search = productSearchDraft.trim();
                  setProductSearch(search);
                  void reload(1, search);
                }}
              >
                <input
                  type="search"
                  value={productSearchDraft}
                  onChange={(event) =>
                    setProductSearchDraft(event.target.value)
                  }
                  placeholder="Search product name or slug"
                  aria-label="Search products"
                  maxLength={80}
                />
                <button type="submit">Search ↗</button>
              </form>
              <div className="admin-table-wrap">
                <table className="admin-table">
                  <thead>
                    <tr>
                      <th>PRODUCT</th>
                      <th>STATUS</th>
                      <th>PRICE</th>
                      <th>STOCK / VARIANTS</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                    {products.map((p) => (
                      <tr key={p.id}>
                        <td>
                          <div className="admin-product-cell">
                            <div className="admin-product-thumb">
                              <Image src={p.image} alt="" fill sizes="58px" />
                            </div>
                            <div>
                              <strong>{p.name}</strong>
                              <small>
                                {p.brand ? `${p.brand} · ` : ""}
                                {p.slug} / {p.category}
                              </small>
                            </div>
                          </div>
                        </td>
                        <td>
                          <span className={`status ${p.status}`}>
                            {p.status}
                          </span>
                        </td>
                        <td>
                          {productPrice(p) !== null
                            ? new Intl.NumberFormat("de-DE", {
                                style: "currency",
                                currency: "EUR",
                              }).format(productPrice(p)! / 100)
                            : "—"}
                        </td>
                        <td>
                          <div className="admin-variants">
                            {p.variants.map((v) => (
                              <div key={v.id} className="variant-actions">
                                <button
                                  title={`Adjust stock for ${v.sku}`}
                                  onClick={() => stock(v)}
                                >
                                  {v.label.en}: <b>{v.stock}</b> ±
                                </button>
                                <button
                                  title={`Edit ${v.sku}`}
                                  onClick={() => editVariant(p.id, v)}
                                >
                                  Edit
                                </button>
                              </div>
                            ))}
                            <button onClick={() => editVariant(p.id)}>
                              + variant
                            </button>
                          </div>
                        </td>
                        <td>
                          <button
                            className="admin-link"
                            onClick={() => {
                              setForm(fromProduct(p));
                              setEditing(p.id);
                            }}
                          >
                            Edit ↗
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <div className="admin-pagination">
                <span>
                  {productPagination.total} products · Page{" "}
                  {productPagination.page} of {productPagination.pages}
                </span>
                <div>
                  <button
                    disabled={productPagination.page <= 1}
                    onClick={() => {
                      const page = productPagination.page - 1;
                      setProductPage(page);
                      void reload(page, productSearch);
                    }}
                  >
                    ← Previous
                  </button>
                  <button
                    disabled={productPagination.page >= productPagination.pages}
                    onClick={() => {
                      const page = productPagination.page + 1;
                      setProductPage(page);
                      void reload(page, productSearch);
                    }}
                  >
                    Next →
                  </button>
                </div>
              </div>
            </>
          )}
          {tab === "categories" && (
            <CategoriesPanel
              categories={categories}
              busy={busy}
              error={error}
              save={saveCategory}
            />
          )}
          {tab === "pricing" && (
            <PricingRulesPanel
              categories={categories}
              send={send}
              onChanged={pricesChanged}
            />
          )}
          {tab === "reprice" && (
            <RepricePanel
              key={repriceKey}
              categories={categories}
              send={send}
              onApplied={pricesChanged}
              initialFilter={repriceFilter}
            />
          )}
          {tab === "orders" && (
            <OrdersPanel
              key={ordersKey}
              send={send}
              initialOrderId={orderToOpen}
              initialQueue={ordersQueue}
              onChanged={pricesChanged}
            />
          )}
          {tab === "purchases" && (
            <PurchasesPanel send={send} onChanged={pricesChanged} />
          )}
          {tab === "import" && (
            <ImportPanel send={send} csrfToken={user.csrfToken} />
          )}
        </div>
      </div>
      {editing && (
        <div
          className="admin-modal-backdrop"
          onMouseDown={(e) => {
            if (e.target === e.currentTarget) setEditing(null);
          }}
        >
          <div className="admin-modal">
            <div className="modal-header">
              <div>
                <p className="eyebrow">CATALOG / PRODUCT EDITOR</p>
                <h2>{editing === "new" ? "New product" : "Edit product"}</h2>
              </div>
              <button onClick={() => setEditing(null)} aria-label="Close">
                ×
              </button>
            </div>
            <form onSubmit={saveProduct}>
              <div className="admin-form-grid">
                {field("slug", "URL slug")}
                {field("image", "Image path")}
                {field("nameCs", "Name · Czech")}
                {field("nameDe", "Name · German")}
                {field("nameEn", "Name · English")}
                {field("shortCs", "Short copy · Czech")}
                {field("shortDe", "Short copy · German")}
                {field("shortEn", "Short copy · English")}
                {field("priceCzk", "Price · Kč", "money")}
                {field("priceEur", "Price · €", "money")}
                {field("featuredRank", "Featured rank", "number")}
                {field("badge", "Badge")}
                {field("brand", "Brand")}
                <label className="admin-field">
                  Category
                  <select
                    value={form.category}
                    onChange={(e) =>
                      setForm({ ...form, category: e.target.value })
                    }
                  >
                    {!categoryBySlug(form.category) && (
                      <option value={form.category}>{form.category}</option>
                    )}
                    {categories.map((c) => (
                      <option
                        key={c.id}
                        value={c.slug}
                        disabled={
                          !isVisible(c, categoryById) &&
                          c.slug !== editingProduct?.category
                        }
                      >
                        {indent(c)}
                        {c.active ? "" : " (inactive)"}
                      </option>
                    ))}
                  </select>
                </label>
                <label className="admin-field">
                  Status
                  <select
                    value={form.status}
                    onChange={(e) =>
                      setForm({ ...form, status: e.target.value })
                    }
                  >
                    <option value="draft">draft</option>
                    <option value="published">published</option>
                  </select>
                </label>
                {field("inBoxCs", "In the box · Czech")}
                {field("inBoxDe", "In the box · German")}
                {field("inBoxEn", "In the box · English")}
              </div>
              <AttributeFields
                title={`Attributes · ${categoryBySlug(form.category)?.names.en || form.category}`}
                definitions={
                  categoryBySlug(form.category)?.effectiveAttributes || []
                }
                values={form.attributes}
                onChange={(attributes) => setForm({ ...form, attributes })}
              />
              <button className="admin-primary" disabled={busy}>
                Save product ↗
              </button>
            </form>
            {editingProduct && (
              <SupplierOffersPanel
                productId={editingProduct.id}
                offers={editingProduct.supplierOffers}
                variants={editingProduct.variants}
                busy={busy}
                error={error}
                save={saveOffer}
              />
            )}
            {editingProduct && (
              <ProductPricingPanel
                key={editingProduct.id}
                variants={editingProduct.variants}
                send={send}
                revision={pricingRevision}
                onApplied={suggestionApplied}
              />
            )}
          </div>
        </div>
      )}
      {variantEditing && (
        <div
          className="admin-modal-backdrop"
          onMouseDown={(e) => {
            if (e.target === e.currentTarget) setVariantEditing(null);
          }}
        >
          <div className="admin-modal">
            <div className="modal-header">
              <div>
                <p className="eyebrow">CATALOG / VARIANT EDITOR</p>
                <h2>{variantEditing.id ? "Edit variant" : "New variant"}</h2>
              </div>
              <button
                onClick={() => setVariantEditing(null)}
                aria-label="Close"
              >
                ×
              </button>
            </div>
            <form onSubmit={saveVariant}>
              <div className="admin-form-grid">
                <label className="admin-field">
                  SKU
                  <input
                    value={variantForm.sku}
                    disabled={Boolean(variantEditing.id)}
                    onChange={(e) =>
                      setVariantForm({
                        ...variantForm,
                        sku: e.target.value.toUpperCase(),
                      })
                    }
                    required
                  />
                </label>
                {(["labelCs", "labelDe", "labelEn"] as const).map((key, i) => (
                  <label className="admin-field" key={key}>
                    Label · {["Czech", "German", "English"][i]}
                    <input
                      value={variantForm[key]}
                      onChange={(e) =>
                        setVariantForm({
                          ...variantForm,
                          [key]: e.target.value,
                        })
                      }
                      required
                    />
                  </label>
                ))}
                <label className="admin-field">
                  Price · Kč
                  <input
                    inputMode="decimal"
                    pattern={moneyPattern}
                    title={moneyHint}
                    placeholder="e.g. 1290"
                    value={variantForm.priceCzk}
                    onChange={(e) =>
                      setVariantForm({
                        ...variantForm,
                        priceCzk: e.target.value,
                      })
                    }
                    required
                  />
                </label>
                <label className="admin-field">
                  Price · €
                  <input
                    inputMode="decimal"
                    pattern={moneyPattern}
                    title={moneyHint}
                    placeholder="e.g. 51.60"
                    value={variantForm.priceEur}
                    onChange={(e) =>
                      setVariantForm({
                        ...variantForm,
                        priceEur: e.target.value,
                      })
                    }
                    required
                  />
                </label>
                <label className="admin-field">
                  Color
                  <input
                    value={variantForm.color}
                    onChange={(e) =>
                      setVariantForm({ ...variantForm, color: e.target.value })
                    }
                  />
                </label>
                <label className="admin-field">
                  Size
                  <input
                    value={variantForm.size}
                    onChange={(e) =>
                      setVariantForm({ ...variantForm, size: e.target.value })
                    }
                  />
                </label>
                <label className="admin-field">
                  MPN
                  <input
                    value={variantForm.mpn}
                    maxLength={64}
                    onChange={(e) =>
                      setVariantForm({ ...variantForm, mpn: e.target.value })
                    }
                  />
                </label>
                <label className="admin-field">
                  EAN / GTIN
                  <input
                    value={variantForm.ean}
                    inputMode="numeric"
                    maxLength={20}
                    onChange={(e) =>
                      setVariantForm({ ...variantForm, ean: e.target.value })
                    }
                  />
                </label>
                <label className="admin-field check-field">
                  <input
                    type="checkbox"
                    checked={variantForm.active}
                    onChange={(e) =>
                      setVariantForm({
                        ...variantForm,
                        active: e.target.checked,
                      })
                    }
                  />{" "}
                  Active in storefront
                </label>
              </div>
              {variantProduct && (
                <AttributeFields
                  title="Attribute overrides · empty inherits from the product"
                  definitions={
                    categoryBySlug(variantProduct.category)
                      ?.effectiveAttributes || []
                  }
                  values={variantForm.attributes}
                  inherited={attributeValues(variantProduct.attributes)}
                  onChange={(attributes) =>
                    setVariantForm({ ...variantForm, attributes })
                  }
                />
              )}
              <fieldset className="admin-fieldset">
                <legend>Reference prices · admin only</legend>
                <p className="variant-note">
                  The manufacturer&apos;s RRP caps the suggested price. It is
                  never shown to customers or as a previous NODRA price. Market
                  price is the lowest a Czech customer would realistically pay
                  elsewhere. Empty amount = not known.
                </p>
                <div className="admin-form-grid">
                  <label className="admin-field">
                    RRP · amount
                    <input
                      inputMode="decimal"
                      pattern={moneyPattern}
                      title={moneyHint}
                      placeholder="e.g. 1499"
                      value={variantForm.rrp}
                      onChange={(e) =>
                        setVariantForm({ ...variantForm, rrp: e.target.value })
                      }
                    />
                  </label>
                  <label className="admin-field">
                    RRP · currency
                    <select
                      value={variantForm.rrpCurrency}
                      onChange={(e) =>
                        setVariantForm({
                          ...variantForm,
                          rrpCurrency: e.target.value,
                        })
                      }
                    >
                      <option value="CZK">CZK · Kč</option>
                      <option value="EUR">EUR · €</option>
                    </select>
                  </label>
                  <label className="admin-field">
                    RRP · source
                    <input
                      maxLength={500}
                      placeholder="URL or short note"
                      value={variantForm.rrpSource}
                      onChange={(e) =>
                        setVariantForm({
                          ...variantForm,
                          rrpSource: e.target.value,
                        })
                      }
                    />
                  </label>
                  <label className="admin-field">
                    RRP · checked on
                    <input
                      type="date"
                      value={variantForm.rrpCheckedAt}
                      onChange={(e) =>
                        setVariantForm({
                          ...variantForm,
                          rrpCheckedAt: e.target.value,
                        })
                      }
                    />
                  </label>
                  <label className="admin-field">
                    Market price · Kč
                    <input
                      inputMode="decimal"
                      pattern={moneyPattern}
                      title={moneyHint}
                      placeholder="e.g. 1190"
                      value={variantForm.marketPrice}
                      onChange={(e) =>
                        setVariantForm({
                          ...variantForm,
                          marketPrice: e.target.value,
                        })
                      }
                    />
                  </label>
                  <label className="admin-field">
                    Market price · checked on
                    <input
                      type="date"
                      value={variantForm.marketCheckedAt}
                      onChange={(e) =>
                        setVariantForm({
                          ...variantForm,
                          marketCheckedAt: e.target.value,
                        })
                      }
                    />
                  </label>
                  <label className="admin-field wide">
                    Market price · source
                    <input
                      maxLength={500}
                      placeholder="URL or shop name"
                      value={variantForm.marketPriceSource}
                      onChange={(e) =>
                        setVariantForm({
                          ...variantForm,
                          marketPriceSource: e.target.value,
                        })
                      }
                    />
                  </label>
                </div>
              </fieldset>
              <p className="variant-note">
                New variants start with zero stock. Use the inventory control to
                add units.
              </p>
              {error && (
                <p className="admin-error" role="alert">
                  {error}
                </p>
              )}
              <button className="admin-primary" disabled={busy}>
                Save variant ↗
              </button>
            </form>
          </div>
        </div>
      )}
    </main>
  );
}
