import { notFound } from "next/navigation";
import { ProductCard } from "@/components/ProductCard";
import {
  api,
  copy,
  isLocale,
  type Card,
  type CategoryNode,
  type Facets,
} from "@/lib/shop";
import Link from "next/link";

type Query = Record<string, string | string[] | undefined>;
const sorts = ["featured", "price_asc", "price_desc", "newest"];

function first(value: string | string[] | undefined): string {
  return (Array.isArray(value) ? value[0] : value)?.trim() || "";
}

// Path from a root down to the category with this slug, empty when it is not in the tree
function findPath(nodes: CategoryNode[], slug: string): CategoryNode[] {
  for (const node of nodes) {
    if (node.slug === slug) return [node];
    const below = findPath(node.children, slug);
    if (below.length) return [node, ...below];
  }
  return [];
}

// First, last, current±2; a single hidden page is shown instead of "…"
function pageWindow(current: number, total: number): (number | null)[] {
  const pages = [1, total];
  for (let p = current - 2; p <= current + 2; p++) {
    if (p > 1 && p < total) pages.push(p);
  }
  const sorted = [...new Set(pages)].sort((a, b) => a - b);
  const out: (number | null)[] = [];
  sorted.forEach((p, i) => {
    const prev = sorted[i - 1];
    if (prev !== undefined && p - prev === 2) out.push(prev + 1);
    else if (prev !== undefined && p - prev > 2) out.push(null);
    out.push(p);
  });
  return out;
}

export default async function Shop({
  params,
  searchParams,
}: {
  params: Promise<{ locale: string }>;
  searchParams: Promise<Query>;
}) {
  const { locale } = await params;
  if (!isLocale(locale)) notFound();
  const raw = await searchParams;
  const t = copy[locale];
  const query = {
    category: first(raw.category),
    // clamp to the contract limits, longer values make the API answer 422
    brand: first(raw.brand).slice(0, 80),
    q: first(raw.q).slice(0, 80),
    // the API answers 422 to anything outside these, so drop junk here
    sort: sorts.includes(first(raw.sort)) ? first(raw.sort) : "",
    page: /^[1-9]\d{0,5}$/.test(first(raw.page)) ? first(raw.page) : "",
    availableOnly: first(raw.availableOnly) === "1" ? "1" : "",
  };
  // attr[key]=value pairs, kept in the order they came in
  const attrs: Record<string, string> = {};
  for (const [key, value] of Object.entries(raw)) {
    const match = /^attr\[([a-z][a-z0-9_]{1,39})\]$/.exec(key);
    if (match && first(value)) attrs[match[1]] = first(value).slice(0, 200);
  }
  const state = new URLSearchParams();
  for (const key of ["category", "brand", "q", "sort"] as const) {
    if (query[key]) state.set(key, query[key]);
  }
  for (const [key, value] of Object.entries(attrs)) {
    state.set(`attr[${key}]`, value);
  }
  if (query.availableOnly) state.set("availableOnly", "1");
  const apiQuery = new URLSearchParams(state);
  apiQuery.set("locale", locale);
  if (query.page) apiQuery.set("page", query.page);

  const [catalog, categories, facets] = await Promise.all([
    api<{ items: Card[]; page: number; pages: number; total: number }>(
      `/api/products?${apiQuery}`,
    ),
    api<CategoryNode[]>(`/api/categories?locale=${locale}`),
    query.category
      ? api<Facets>(
          `/api/categories/${encodeURIComponent(query.category)}/facets?locale=${locale}`,
        ).catch(() => null)
      : Promise.resolve(null),
  ]);

  // Links keep the current state; an empty value in the patch drops the key
  const href = (patch: Record<string, string> = {}) => {
    const next = new URLSearchParams(state);
    for (const [k, v] of Object.entries(patch)) {
      if (v) next.set(k, v);
      else next.delete(k);
    }
    const qs = next.toString();
    return `/${locale}/shop${qs ? `?${qs}` : ""}`;
  };
  // Filters belong to one category, so switching category starts clean
  const categoryHref = (slug: string) => {
    const next = new URLSearchParams();
    if (slug) next.set("category", slug);
    for (const key of ["q", "sort", "availableOnly"]) {
      const value = state.get(key);
      if (value) next.set(key, value);
    }
    const qs = next.toString();
    return `/${locale}/shop${qs ? `?${qs}` : ""}`;
  };
  const resetHref = () => {
    const next = new URLSearchParams(state);
    next.delete("brand");
    for (const key of Object.keys(attrs)) next.delete(`attr[${key}]`);
    const qs = next.toString();
    return `/${locale}/shop${qs ? `?${qs}` : ""}`;
  };

  const path = query.category ? findPath(categories, query.category) : [];
  const selected = path[path.length - 1];
  const roots = categories.filter((c) => c.count > 0 || c === path[0]);
  const visibleChildren = (node?: CategoryNode) =>
    node ? node.children.filter((c) => c.count > 0 || c === selected) : [];
  // Show the selected category's children, or its siblings when it is a leaf
  const chipOwner = visibleChildren(selected).length
    ? selected
    : path.length > 1
      ? path[path.length - 2]
      : undefined;
  const chips = visibleChildren(chipOwner);

  const filtersActive = Boolean(query.brand || Object.keys(attrs).length);
  const facetAttributes =
    facets?.attributes.filter((a) => a.values.length) ?? [];
  const showFilters =
    Boolean(facets) &&
    (Boolean(facets?.brands.length) || facetAttributes.length > 0);
  // Remount the forms when the URL changes so the defaultValues follow it
  const formKey = state.toString();

  return (
    <main className="shop-main">
      <div className="shop-heading">
        <p className="eyebrow">NODRA / THE COLLECTION</p>
        <h1>
          {t.shop}
          <sup>{catalog.total.toString().padStart(2, "0")}</sup>
        </h1>
        <p>{t.featuredText}</p>
      </div>
      <div className="shop-toolbar">
        <div className="category-tabs">
          <Link
            className={!query.category ? "selected" : ""}
            href={categoryHref("")}
          >
            {t.all}
          </Link>
          {roots.map((c) => (
            <Link
              key={c.slug}
              className={path[0]?.slug === c.slug ? "selected" : ""}
              href={categoryHref(c.slug)}
            >
              {c.name} <small>{c.count}</small>
            </Link>
          ))}
        </div>
        <form
          key={formKey}
          id="shop-form"
          action={`/${locale}/shop`}
          className="shop-controls"
        >
          {query.category && (
            <input type="hidden" name="category" value={query.category} />
          )}
          <input
            type="search"
            name="q"
            maxLength={80}
            defaultValue={query.q}
            placeholder={t.search}
            aria-label={t.search}
          />
          <select
            name="sort"
            defaultValue={query.sort || "featured"}
            aria-label={t.sort}
          >
            <option value="featured">{t.recommended}</option>
            <option value="price_asc">{t.low}</option>
            <option value="price_desc">{t.high}</option>
            <option value="newest">{t.newest}</option>
          </select>
          <label className="stock-filter">
            <input
              type="checkbox"
              name="availableOnly"
              value="1"
              defaultChecked={query.availableOnly === "1"}
            />
            {t.availableOnly}
          </label>
          <button aria-label={t.search}>↗</button>
        </form>
      </div>
      {chipOwner && chips.length > 0 && (
        <nav className="category-chips" aria-label={t.subcategories}>
          <Link
            className={selected === chipOwner ? "selected" : ""}
            href={categoryHref(chipOwner.slug)}
          >
            {t.all}
          </Link>
          {chips.map((c) => (
            <Link
              key={c.slug}
              className={selected?.slug === c.slug ? "selected" : ""}
              href={categoryHref(c.slug)}
            >
              {c.name} <small>{c.count}</small>
            </Link>
          ))}
        </nav>
      )}
      {showFilters && facets && (
        <div className="shop-filters" key={formKey}>
          <span className="eyebrow">{t.filters}</span>
          {facets.brands.length > 0 && (
            <label>
              <span>{t.brand}</span>
              <select form="shop-form" name="brand" defaultValue={query.brand}>
                <option value="">{t.anyBrand}</option>
                {query.brand &&
                  !facets.brands.some((b) => b.value === query.brand) && (
                    <option value={query.brand}>{query.brand}</option>
                  )}
                {facets.brands.map((b) => (
                  <option key={b.value} value={b.value}>
                    {`${b.value} (${b.count})`}
                  </option>
                ))}
              </select>
            </label>
          )}
          {facetAttributes.map((a) => {
            const current = attrs[a.key] || "";
            const known = a.values.some((v) => v.value === current);
            const unit = a.unit ? ` ${a.unit}` : "";
            return (
              <label key={a.key}>
                <span>{a.label}</span>
                <select
                  form="shop-form"
                  name={`attr[${a.key}]`}
                  defaultValue={current}
                >
                  <option value="">{t.anyValue}</option>
                  {current && !known && (
                    // e.g. attr[mount]=handlebar,top_tube typed by hand
                    <option value={current}>
                      {current
                        .split(",")
                        .map(
                          (part) =>
                            a.values.find((v) => v.value === part)?.label ||
                            part,
                        )
                        .join(", ")}
                    </option>
                  )}
                  {a.values.map((v) => (
                    <option key={v.value} value={v.value}>
                      {`${v.label}${unit} (${v.count})`}
                    </option>
                  ))}
                </select>
              </label>
            );
          })}
          <button form="shop-form" className="filter-apply">
            {t.applyFilters}
          </button>
          {filtersActive && (
            <Link href={resetHref()} className="filter-reset">
              {t.resetFilters} ×
            </Link>
          )}
        </div>
      )}
      {catalog.items.length ? (
        <div className="product-grid shop-grid">
          {catalog.items.map((product, index) => (
            <ProductCard
              key={product.id}
              product={product}
              locale={locale}
              priority={index === 0}
            />
          ))}
        </div>
      ) : (
        <div className="empty-state">
          <h2>{t.noResults}</h2>
          <Link
            href={filtersActive ? resetHref() : `/${locale}/shop`}
            className="button button-dark"
          >
            {filtersActive ? t.resetFilters : t.all} ↗
          </Link>
        </div>
      )}
      {catalog.pages > 1 && (
        <nav className="pagination" aria-label={t.pagination}>
          {catalog.page > 1 && (
            <Link
              href={href({
                page: catalog.page > 2 ? String(catalog.page - 1) : "",
              })}
              aria-label={t.previous}
            >
              ←
            </Link>
          )}
          {pageWindow(catalog.page, catalog.pages).map((p, i) =>
            p === null ? (
              <span key={`gap-${i}`} className="gap">
                …
              </span>
            ) : (
              <Link
                key={p}
                className={catalog.page === p ? "current" : ""}
                aria-current={catalog.page === p ? "page" : undefined}
                href={href({ page: p > 1 ? String(p) : "" })}
              >
                {p}
              </Link>
            ),
          )}
          {catalog.page < catalog.pages && (
            <Link
              href={href({ page: String(catalog.page + 1) })}
              aria-label={t.next}
            >
              →
            </Link>
          )}
        </nav>
      )}
    </main>
  );
}
