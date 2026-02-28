import { notFound } from "next/navigation";
import { ProductCard } from "@/components/ProductCard";
import { api, copy, isLocale, type Card } from "@/lib/shop";
import Link from "next/link";
export default async function Shop({
  params,
  searchParams,
}: {
  params: Promise<{ locale: string }>;
  searchParams: Promise<{
    category?: string;
    q?: string;
    sort?: string;
    page?: string;
    availableOnly?: string;
  }>;
}) {
  const { locale } = await params;
  if (!isLocale(locale)) notFound();
  const query = await searchParams;
  const t = copy[locale];
  const url = new URLSearchParams({ locale });
  if (query.category) url.set("category", query.category);
  if (query.q) url.set("q", query.q);
  if (query.sort) url.set("sort", query.sort);
  if (query.page) url.set("page", query.page);
  if (query.availableOnly === "1") url.set("availableOnly", "1");
  const [catalog, categories] = await Promise.all([
    api<{ items: Card[]; page: number; pages: number; total: number }>(
      `/api/products?${url}`,
    ),
    api<{ slug: string; name: string; count: number }[]>(
      `/api/categories?locale=${locale}`,
    ),
  ]);
  const href = (patch: Record<string, string>) => {
    const next = new URLSearchParams(url);
    next.delete("locale");
    for (const [k, v] of Object.entries(patch)) {
      if (v) next.set(k, v);
      else next.delete(k);
    }
    return `/${locale}/shop?${next}`;
  };
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
            href={href({ category: "", page: "" })}
          >
            {t.all}
          </Link>
          {categories.map((c) => (
            <Link
              key={c.slug}
              className={query.category === c.slug ? "selected" : ""}
              href={href({ category: c.slug, page: "" })}
            >
              {c.name} <small>{c.count}</small>
            </Link>
          ))}
        </div>
        <form action={`/${locale}/shop`} className="shop-controls">
          {query.category && (
            <input type="hidden" name="category" value={query.category} />
          )}
          <input
            type="search"
            name="q"
            defaultValue={query.q || ""}
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
          <Link href={`/${locale}/shop`} className="button button-dark">
            {t.all} ↗
          </Link>
        </div>
      )}
      {catalog.pages > 1 && (
        <div className="pagination">
          {Array.from({ length: catalog.pages }, (_, i) => (
            <Link
              key={i}
              className={catalog.page === i + 1 ? "current" : ""}
              href={href({ page: String(i + 1) })}
            >
              {i + 1}
            </Link>
          ))}
        </div>
      )}
    </main>
  );
}
