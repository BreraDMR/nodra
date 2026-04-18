import Link from "next/link";
import { BasketLink } from "@/components/BasketLink";
import { api, copy, type CategoryNode, type Locale } from "@/lib/shop";
export async function StoreHeader({ locale }: { locale: Locale }) {
  const t = copy[locale];
  // No categories when the API is down, the header itself still renders
  const categories = await api<CategoryNode[]>(
    `/api/categories?locale=${locale}`,
  ).catch(() => [] as CategoryNode[]);
  const roots = categories.filter((c) => c.count > 0);
  return (
    <>
      <div className="announcement">
        {t.announcement} <span aria-hidden="true">✦</span> {t.location}
      </div>
      <header className="site-header">
        <div className="header-inner">
          <Link
            href={`/${locale}`}
            className="wordmark"
            aria-label={t.homeLabel}
          >
            NODRA
          </Link>
          <nav aria-label={t.mainNav}>
            <Link href={`/${locale}/shop`}>{t.shop}</Link>
            <a href={`/${locale}#story`}>{t.story}</a>
          </nav>
          <div className="header-actions">
            <div className="locale-switch" role="group" aria-label={t.language}>
              {(["cs", "de", "en"] as const).map((l) => (
                <Link
                  key={l}
                  href={`/${l}`}
                  className={locale === l ? "active" : ""}
                >
                  {l.toUpperCase()}
                </Link>
              ))}
            </div>
            <BasketLink locale={locale} />
            <Link href={`/${locale}/account`} className="account-link">
              {t.account}
            </Link>
          </div>
        </div>
        <div className="header-tools">
          {/* Plain GET form, so search works before hydration too */}
          <form
            role="search"
            action={`/${locale}/shop`}
            className="header-search"
          >
            <label htmlFor="header-search" className="visually-hidden">
              {t.search}
            </label>
            <input
              id="header-search"
              type="search"
              name="q"
              maxLength={80}
              placeholder={t.search}
              enterKeyHint="search"
            />
            <button type="submit">{t.searchSubmit}</button>
          </form>
          {roots.length > 0 && (
            <nav className="header-categories" aria-label={t.categories}>
              <Link href={`/${locale}/shop`}>{t.all}</Link>
              {roots.map((c) => (
                <Link
                  key={c.slug}
                  href={`/${locale}/shop?category=${encodeURIComponent(c.slug)}`}
                >
                  {c.name}
                </Link>
              ))}
            </nav>
          )}
        </div>
      </header>
    </>
  );
}
