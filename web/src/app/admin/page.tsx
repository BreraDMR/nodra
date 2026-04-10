"use client";
import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import Image from "next/image";
import { AttributeFields } from "./AttributeFields";
import { CategoriesPanel } from "./CategoriesPanel";
import { SupplierOffersPanel } from "./SupplierOffersPanel";
import {
  attributePayload,
  attributeValues,
  indent,
  isVisible,
  json,
  type AdminCategory,
  type AttributeValues,
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
type Order = {
  id: string;
  reference: string;
  status: string;
  customerName: string;
  email: string;
  country: string;
  total: { amount: number; currency: string };
  createdAt: string;
};
type Dashboard = {
  orders: number;
  openOrders: number;
  revenueEurMinor: number;
  products: number;
  lowStock: { sku: string; stock: number; product: string }[];
  recentOrders: Order[];
};
type Tab = "overview" | "products" | "categories" | "orders";
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
  priceCzk: 0,
  priceEur: 0,
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
  priceCzk: 0,
  priceEur: 0,
  active: true,
  color: "",
  size: "",
  mpn: "",
  ean: "",
  attributes: {} as AttributeValues,
};
type VariantForm = typeof variantFresh;
function fromProduct(p: Product): Form {
  const base = [...p.variants].sort(
    (a, b) =>
      Number(b.active && b.stock > 0) - Number(a.active && a.stock > 0) ||
      Number(b.active) - Number(a.active) ||
      a.sku.localeCompare(b.sku),
  )[0];
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
    priceCzk: base?.priceCzk || 0,
    priceEur: base?.priceEur || 0,
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
  const [orders, setOrders] = useState<Order[]>([]);
  const [orderPage, setOrderPage] = useState(1);
  const [orderPagination, setOrderPagination] = useState({
    page: 1,
    pages: 1,
    total: 0,
  });
  const [selectedOrder, setSelectedOrder] = useState<string | null>(null);
  const [orderDetail, setOrderDetail] = useState<Record<
    string,
    unknown
  > | null>(null);
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
  const loadCategories = useCallback(async () => {
    try {
      setCategories(await json("/api/admin/categories"));
    } catch (e) {
      setError(e instanceof Error ? e.message : "Could not load categories");
    }
  }, []);
  const reload = useCallback(
    async (page: number, search: string, ordersPage: number) => {
      try {
        const query = new URLSearchParams({ page: String(page) });
        if (search) query.set("q", search);
        const [d, p, o] = await Promise.all([
          json("/api/admin/dashboard"),
          json(`/api/admin/products?${query}`),
          json(`/api/admin/orders?page=${ordersPage}`),
        ]);
        setDashboard(d);
        setProducts(p.items);
        setProductPagination({ page: p.page, pages: p.pages, total: p.total });
        setOrders(o.items);
        setOrderPagination({ page: o.page, pages: o.pages, total: o.total });
      } catch (e) {
        setError(e instanceof Error ? e.message : "Could not load admin data");
      }
    },
    [],
  );
  useEffect(() => {
    json("/api/admin/me")
      .then(async (u: User) => {
        setUser(u);
        await Promise.all([reload(1, "", 1), loadCategories()]);
      })
      .catch(() => {})
      .finally(() => setReady(true));
  }, [reload, loadCategories]);
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
      await Promise.all([reload(1, "", 1), loadCategories()]);
    } catch (e) {
      setError(e instanceof Error ? e.message : "Sign-in failed");
    } finally {
      setBusy(false);
    }
  }
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
      await reload(productPage, productSearch, orderPage);
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
        priceCzk: Number(form.priceCzk),
        priceEur: Number(form.priceEur),
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
    const ok = await mutate(
      variantEditing.id
        ? `/api/admin/variants/${variantEditing.id}`
        : `/api/admin/products/${variantEditing.productId}/variants`,
      variantEditing.id ? "PUT" : "POST",
      {
        ...variantForm,
        color: variantForm.color || null,
        size: variantForm.size || null,
        mpn: variantForm.mpn.trim() || null,
        ean: variantForm.ean.replace(/[\s-]/g, "") || null,
        attributes: attributePayload(
          variantForm.attributes,
          categoryBySlug(
            products.find((p) => p.id === variantEditing.productId)?.category ||
              "",
          )?.effectiveAttributes,
        ),
        priceCzk: Number(variantForm.priceCzk),
        priceEur: Number(variantForm.priceEur),
      },
    );
    if (ok) setVariantEditing(null);
  }
  function editVariant(productId: string, variant?: Variant) {
    setVariantEditing({ productId, id: variant?.id });
    setVariantForm(
      variant
        ? {
            sku: variant.sku,
            labelCs: variant.label.cs,
            labelDe: variant.label.de,
            labelEn: variant.label.en,
            priceCzk: variant.priceCzk,
            priceEur: variant.priceEur,
            active: variant.active,
            color: variant.color || "",
            size: variant.size || "",
            mpn: variant.mpn || "",
            ean: variant.ean || "",
            attributes: attributeValues(variant.attributes),
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
  async function advance(order: Order, status: string) {
    if (
      status === "cancelled" &&
      !confirm(`Cancel ${order.reference} and return stock?`)
    )
      return;
    return await mutate(`/api/admin/orders/${order.id}/status`, "PATCH", {
      status,
    });
  }
  async function openOrder(id: string) {
    setSelectedOrder(id);
    try {
      setOrderDetail(await json(`/api/admin/orders/${id}`));
    } catch (e) {
      setError(e instanceof Error ? e.message : "Could not load order");
    }
  }
  const field = (
    key: keyof Form,
    label: string,
    kind: "text" | "number" = "text",
  ) => (
    <label className="admin-field">
      {label}
      <input
        type={kind}
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
            NODRA<span>®</span>
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
            NODRA<span>®</span>
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
              aria-label="Orders"
              className={tab === "orders" ? "active" : ""}
              onClick={() => setTab("orders")}
            >
              ▤ <span>Orders</span>
              <small>{orderPagination.total}</small>
            </button>
          </nav>
        </div>
        <div className="admin-sidebar-bottom">
          <Link href="/cs" target="_blank">
            ↗ &nbsp; View storefront
          </Link>
          <button
            onClick={async () => {
              await fetch("/api/admin/logout", { method: "POST" });
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
                  <strong>
                    {new Intl.NumberFormat("de-DE", {
                      style: "currency",
                      currency: "EUR",
                    }).format((dashboard?.revenueEurMinor || 0) / 100)}
                  </strong>
                  <small>Demo orders · EUR</small>
                </div>
                <div>
                  <span>02 / ORDERS</span>
                  <strong>{dashboard?.orders || 0}</strong>
                  <small>{dashboard?.openOrders || 0} need attention</small>
                </div>
                <div>
                  <span>03 / PRODUCTS</span>
                  <strong>{dashboard?.products || 0}</strong>
                  <small>In the collection</small>
                </div>
                <div>
                  <span>04 / LOW STOCK</span>
                  <strong>{dashboard?.lowStock.length || 0}</strong>
                  <small>Variants at 5 or below</small>
                </div>
              </div>
              <div className="admin-overview-grid">
                <section className="admin-panel">
                  <div className="panel-head">
                    <div>
                      <p className="eyebrow">LIVE OPERATIONS</p>
                      <h2>Recent orders</h2>
                    </div>
                    <button onClick={() => setTab("orders")}>View all ↗</button>
                  </div>
                  {dashboard?.recentOrders.length ? (
                    dashboard.recentOrders.map((o) => (
                      <div className="mini-order" key={o.id}>
                        <span className="order-ref">{o.reference}</span>
                        <span>{o.customerName}</span>
                        <span className={`status ${o.status}`}>{o.status}</span>
                        <strong>
                          {new Intl.NumberFormat("de-DE", {
                            style: "currency",
                            currency: o.total.currency,
                          }).format(o.total.amount / 100)}
                        </strong>
                      </div>
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
                      <h2>Low stock</h2>
                    </div>
                    <button onClick={() => setTab("products")}>Manage ↗</button>
                  </div>
                  {dashboard?.lowStock.length ? (
                    dashboard.lowStock.map((x) => (
                      <div className="low-stock" key={x.sku}>
                        <div>
                          <strong>{x.product}</strong>
                          <small>{x.sku}</small>
                        </div>
                        <b>{x.stock} left</b>
                      </div>
                    ))
                  ) : (
                    <p className="admin-empty">
                      All variants have healthy stock.
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
                  void reload(1, search, orderPage);
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
                      void reload(page, productSearch, orderPage);
                    }}
                  >
                    ← Previous
                  </button>
                  <button
                    disabled={productPagination.page >= productPagination.pages}
                    onClick={() => {
                      const page = productPagination.page + 1;
                      setProductPage(page);
                      void reload(page, productSearch, orderPage);
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
          {tab === "orders" && (
            <>
              <div className="admin-heading compact">
                <div>
                  <p className="eyebrow">OPERATIONS / FULFILMENT</p>
                  <h1>
                    Orders<span>.</span>
                  </h1>
                  <p>From first click to the final mile.</p>
                </div>
              </div>
              <div className="admin-table-wrap">
                <table className="admin-table">
                  <thead>
                    <tr>
                      <th>REFERENCE</th>
                      <th>CUSTOMER</th>
                      <th>DATE</th>
                      <th>STATUS</th>
                      <th>TOTAL</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                    {orders.map((o) => (
                      <tr key={o.id}>
                        <td>
                          <strong>{o.reference}</strong>
                        </td>
                        <td>
                          <strong>{o.customerName}</strong>
                          <small>{o.email}</small>
                        </td>
                        <td>
                          {new Date(o.createdAt).toLocaleDateString("en-GB")}
                        </td>
                        <td>
                          <span className={`status ${o.status}`}>
                            {o.status}
                          </span>
                        </td>
                        <td>
                          {new Intl.NumberFormat("de-DE", {
                            style: "currency",
                            currency: o.total.currency,
                          }).format(o.total.amount / 100)}
                        </td>
                        <td>
                          <button
                            className="admin-link"
                            onClick={() => openOrder(o.id)}
                          >
                            Open ↗
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
                {!orders.length && (
                  <p className="admin-empty">No demo orders yet.</p>
                )}
              </div>
              <div className="admin-pagination">
                <span>
                  {orderPagination.total} orders · Page {orderPagination.page}{" "}
                  of {orderPagination.pages}
                </span>
                <div>
                  <button
                    disabled={orderPagination.page <= 1}
                    onClick={() => {
                      const page = orderPagination.page - 1;
                      setOrderPage(page);
                      void reload(productPage, productSearch, page);
                    }}
                  >
                    ← Previous
                  </button>
                  <button
                    disabled={orderPagination.page >= orderPagination.pages}
                    onClick={() => {
                      const page = orderPagination.page + 1;
                      setOrderPage(page);
                      void reload(productPage, productSearch, page);
                    }}
                  >
                    Next →
                  </button>
                </div>
              </div>
            </>
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
                {field("priceCzk", "Price CZK · cents", "number")}
                {field("priceEur", "Price EUR · cents", "number")}
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
                save={mutate}
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
                  Price CZK · cents
                  <input
                    type="number"
                    min="0"
                    value={variantForm.priceCzk}
                    onChange={(e) =>
                      setVariantForm({
                        ...variantForm,
                        priceCzk: Number(e.target.value),
                      })
                    }
                    required
                  />
                </label>
                <label className="admin-field">
                  Price EUR · cents
                  <input
                    type="number"
                    min="0"
                    value={variantForm.priceEur}
                    onChange={(e) =>
                      setVariantForm({
                        ...variantForm,
                        priceEur: Number(e.target.value),
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
              <p className="variant-note">
                New variants start with zero stock. Use the inventory control to
                add units.
              </p>
              <button className="admin-primary" disabled={busy}>
                Save variant ↗
              </button>
            </form>
          </div>
        </div>
      )}
      {selectedOrder && orderDetail && (
        <div
          className="admin-modal-backdrop"
          onMouseDown={(e) => {
            if (e.target === e.currentTarget) setSelectedOrder(null);
          }}
        >
          <div className="admin-modal order-modal">
            <div className="modal-header">
              <div>
                <p className="eyebrow">FULFILMENT / ORDER</p>
                <h2>{String(orderDetail.reference)}</h2>
              </div>
              <button onClick={() => setSelectedOrder(null)} aria-label="Close">
                ×
              </button>
            </div>
            <div className="order-detail-meta">
              <span>
                Status: <b>{String(orderDetail.status)}</b>
              </span>
              <span>
                {String((orderDetail.customer as { name: string })?.name)} ·{" "}
                {String((orderDetail.customer as { email: string })?.email)}
              </span>
              <span>
                {String((orderDetail.customer as { address: string })?.address)}
                ,{" "}
                {String(
                  (orderDetail.customer as { postalCode: string })?.postalCode,
                )}{" "}
                ·{" "}
                {String(
                  (orderDetail.customer as { district: string })?.district,
                )}{" "}
                ·{" "}
                {String((orderDetail.customer as { country: string })?.country)}
              </span>
            </div>
            <div className="order-detail-lines">
              {(
                (orderDetail.items || []) as {
                  name: string;
                  variant: string;
                  quantity: number;
                  lineTotal: number;
                }[]
              ).map((x, i) => (
                <div key={i}>
                  <span>
                    {x.name} / {x.variant} × {x.quantity}
                  </span>
                  <strong>
                    {new Intl.NumberFormat("de-DE", {
                      style: "currency",
                      currency: (orderDetail.total as { currency: string })
                        .currency,
                    }).format(x.lineTotal / 100)}
                  </strong>
                </div>
              ))}
            </div>
            <div className="order-detail-total">
              <span>Total</span>
              <strong>
                {new Intl.NumberFormat("de-DE", {
                  style: "currency",
                  currency: (orderDetail.total as { currency: string })
                    .currency,
                }).format(
                  (orderDetail.total as { amount: number }).amount / 100,
                )}
              </strong>
            </div>
            <div className="order-actions">
              {orders.find((x) => x.id === selectedOrder)?.status ===
                "placed" && (
                <>
                  <button
                    onClick={async () => {
                      const o = orders.find((x) => x.id === selectedOrder)!;
                      if (await advance(o, "processing")) await openOrder(o.id);
                    }}
                  >
                    Mark processing
                  </button>
                  <button
                    className="danger"
                    onClick={async () => {
                      const o = orders.find((x) => x.id === selectedOrder)!;
                      if (await advance(o, "cancelled")) await openOrder(o.id);
                    }}
                  >
                    Cancel order
                  </button>
                </>
              )}
              {orders.find((x) => x.id === selectedOrder)?.status ===
                "processing" && (
                <>
                  <button
                    onClick={async () => {
                      const o = orders.find((x) => x.id === selectedOrder)!;
                      if (await advance(o, "shipped")) await openOrder(o.id);
                    }}
                  >
                    Mark shipped
                  </button>
                  <button
                    className="danger"
                    onClick={async () => {
                      const o = orders.find((x) => x.id === selectedOrder)!;
                      if (await advance(o, "cancelled")) await openOrder(o.id);
                    }}
                  >
                    Cancel order
                  </button>
                </>
              )}
              {orders.find((x) => x.id === selectedOrder)?.status ===
                "shipped" && (
                <button
                  onClick={async () => {
                    const o = orders.find((x) => x.id === selectedOrder)!;
                    if (await advance(o, "completed")) await openOrder(o.id);
                  }}
                >
                  Complete order
                </button>
              )}
            </div>
          </div>
        </div>
      )}
    </main>
  );
}
